<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignTaskStatus;
use App\Enums\LeadActivityType;
use App\Enums\StepType;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\CampaignStep;
use App\Models\CampaignTask;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manual steps of multichannel sequences. When a lead reaches a LinkedIn,
 * call or other manual step, a task is created and the lead waits (no
 * next_send_at) until someone completes or skips it; then the sequence
 * moves on to the next step after its delay.
 */
class CampaignTasks
{
    public function __construct(
        protected CampaignMessageBuilder $builder,
        protected TemplateRenderer $renderer,
    ) {}

    /**
     * Turns every due lead whose next step is manual into a task.
     *
     * @param  Collection<int, Campaign>  $campaigns  Active campaigns
     * @return int Tasks created
     */
    public function createDue(Collection $campaigns, CarbonInterface $now): int
    {
        if ($campaigns->isEmpty()) {
            return 0;
        }

        $created = 0;

        CampaignLead::query()
            ->whereIn('campaign_id', $campaigns->modelKeys())
            ->where('status', CampaignLeadStatus::Active->value)
            ->where('next_send_at', '<=', $now)
            ->whereExists(fn (Builder $query) => $query->select(DB::raw(1))
                ->from('campaign_steps')
                ->whereColumn('campaign_steps.campaign_id', 'campaign_leads.campaign_id')
                ->whereRaw('campaign_steps.position = campaign_leads.steps_sent + 1')
                ->where('campaign_steps.type', '!=', StepType::Email->value))
            ->with(['campaign', 'lead'])
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (CampaignLead $campaignLead) use (&$created, $now): void {
                if ($this->open($campaignLead, $campaignLead->steps_sent + 1, $now)) {
                    $created++;
                }
            });

        return $created;
    }

    /**
     * Creates the task for one lead's manual step and parks the lead.
     * Safe to call twice: one task per lead per step.
     */
    public function open(CampaignLead $campaignLead, int $position, ?CarbonInterface $now = null): ?CampaignTask
    {
        $now ??= now();
        $step = CampaignStep::query()->where('campaign_id', $campaignLead->campaign_id)->where('position', $position)->first();

        if (! $step || $step->isEmail()) {
            return null;
        }

        $campaign = $campaignLead->campaign ?? Campaign::query()->find($campaignLead->campaign_id);
        $lead = $campaignLead->lead ?? Lead::query()->find($campaignLead->lead_id);

        if (! $campaign || ! $lead) {
            return null;
        }

        // Park the lead first (conditional, so a parallel run can't double up).
        $parked = CampaignLead::query()
            ->whereKey($campaignLead->getKey())
            ->where('status', CampaignLeadStatus::Active->value)
            ->where('steps_sent', $position - 1)
            ->whereNotNull('next_send_at')
            ->update(['next_send_at' => null, 'updated_at' => $now]);

        if ($parked !== 1) {
            return null;
        }

        try {
            // Own transaction (a savepoint when nested) so a duplicate can't
            // abort an outer PostgreSQL transaction.
            return DB::transaction(fn (): CampaignTask => CampaignTask::query()->create([
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->getKey(),
                'campaign_lead_id' => $campaignLead->getKey(),
                'lead_id' => $lead->getKey(),
                'campaign_step_id' => $step->getKey(),
                'step_position' => $position,
                'type' => $step->type,
                'title' => mb_substr($this->render((string) ($step->subject ?: $step->type->getLabel()), $lead, $step), 0, 255),
                'instructions' => filled(strip_tags((string) $step->body)) ? $this->render($this->builder->htmlToText((string) $step->body), $lead, $step) : null,
                'due_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function complete(CampaignTask $task, ?User $user = null): void
    {
        $this->close($task, CampaignTaskStatus::Done, $user);
    }

    public function skip(CampaignTask $task, ?User $user = null): void
    {
        $this->close($task, CampaignTaskStatus::Skipped, $user);
    }

    protected function close(CampaignTask $task, CampaignTaskStatus $status, ?User $user): void
    {
        $closed = CampaignTask::query()
            ->whereKey($task->getKey())
            ->where('status', CampaignTaskStatus::Open->value)
            ->update(['status' => $status->value, 'completed_at' => now(), 'completed_by' => $user?->getKey(), 'updated_at' => now()]);

        if ($closed !== 1) {
            return;
        }

        $task->refresh();

        if ($status === CampaignTaskStatus::Done && ($lead = Lead::query()->find($task->lead_id))) {
            $lead->logActivity(LeadActivityType::TaskCompleted, "{$task->type->getLabel()}: {$task->title}", ['campaign_task_id' => $task->getKey()]);
        }

        // Move the sequence on, if the lead is still waiting on this step.
        $next = CampaignStep::query()
            ->where('campaign_id', $task->campaign_id)
            ->where('position', $task->step_position + 1)
            ->first();

        CampaignLead::query()
            ->whereKey($task->campaign_lead_id)
            ->where('status', CampaignLeadStatus::Active->value)
            ->where('steps_sent', $task->step_position - 1)
            ->update([
                'steps_sent' => $task->step_position,
                'status' => $next ? CampaignLeadStatus::Active->value : CampaignLeadStatus::Completed->value,
                'next_send_at' => $next ? now()->addDays($next->delay_days) : null,
                'updated_at' => now(),
            ]);
    }

    protected function render(string $template, Lead $lead, CampaignStep $step): string
    {
        return trim($this->renderer->render($template, $this->builder->variables($lead, null), "{$lead->getKey()}:{$step->getKey()}"));
    }
}
