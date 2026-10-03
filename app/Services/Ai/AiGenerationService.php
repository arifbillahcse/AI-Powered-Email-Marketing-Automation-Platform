<?php

namespace App\Services\Ai;

use App\Enums\AiContentType;
use App\Enums\AiGenerationStatus;
use App\Jobs\DispatchAiGenerations;
use App\Models\AiGeneration;
use App\Models\AiPromptTemplate;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Lead;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The AI review queue: create generations for a campaign's audience, and
 * approve, edit, reject or regenerate them. Only approved content is used by
 * the sender (see approvedVariables()).
 */
class AiGenerationService
{
    public function __construct(
        protected CampaignAudience $audience,
    ) {}

    /**
     * Queue AI content for every lead in the campaign's audience.
     *
     * Leads that already have this content keep it, unless $redo is set:
     * then everything not approved yet is generated again.
     *
     * @return int Generations queued
     */
    public function queue(Campaign $campaign, CampaignStep $step, AiContentType $type, ?AiPromptTemplate $template, ?User $by = null, bool $redo = false): int
    {
        $workspaceId = (int) $campaign->workspace_id;
        $stepId = (int) $step->getKey();
        // Inlined literals: PostgreSQL can't type bound parameters in an
        // INSERT ... SELECT list (see CLAUDE.md).
        $now = "TIMESTAMP '".now()->format('Y-m-d H:i:s')."'";
        $columns = ['workspace_id', 'campaign_id', 'campaign_step_id', 'lead_id', 'type', 'created_at', 'updated_at'];
        $select = "{$workspaceId} as workspace_id, ".(int) $campaign->getKey().' as campaign_id, '
            ."{$stepId} as campaign_step_id, leads.id as lead_id, '{$type->value}' as type, {$now} as created_at, {$now} as updated_at";

        if ($template) {
            $columns[] = 'ai_prompt_template_id';
            $select .= ', '.(int) $template->getKey().' as ai_prompt_template_id';
        }

        $queued = DB::table('ai_generations')->insertOrIgnoreUsing(
            $columns,
            $this->audience->query($campaign)->toBase()->select([])->selectRaw($select),
        );

        if ($redo) {
            $queued += AiGeneration::query()
                ->where('workspace_id', $workspaceId)
                ->where('campaign_step_id', $stepId)
                ->where('type', $type->value)
                ->whereIn('status', [AiGenerationStatus::Ready->value, AiGenerationStatus::Rejected->value, AiGenerationStatus::Failed->value])
                ->update([
                    'status' => AiGenerationStatus::Pending->value,
                    'ai_prompt_template_id' => $template?->getKey(),
                    'error' => null,
                    'updated_at' => now(),
                ]);
        }

        $this->dispatch($workspaceId, $by);

        return $queued;
    }

    /**
     * Start (or continue) working through the workspace's pending generations.
     */
    public function dispatch(int $workspaceId, ?User $by = null): void
    {
        DispatchAiGenerations::dispatch($workspaceId, $by?->getKey(), (string) Str::uuid());
    }

    public function approve(AiGeneration $generation, User $by): void
    {
        if (blank($generation->output) || $generation->status === AiGenerationStatus::Pending) {
            return;
        }

        $generation->forceFill([
            'status' => AiGenerationStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $by->getKey(),
        ])->save();
    }

    /**
     * Save a human edit. Edited content is approved: the reviewer wrote it.
     */
    public function edit(AiGeneration $generation, string $output, User $by): void
    {
        $generation->forceFill([
            'output' => Str::limit(trim(str_replace(["\r\n", "\r"], "\n", $output)), PromptBuilder::MAX_LENGTH[$generation->type->value], ''),
            'edited' => true,
            'status' => AiGenerationStatus::Approved,
            'error' => null,
            'approved_at' => now(),
            'approved_by' => $by->getKey(),
        ])->save();
    }

    public function reject(AiGeneration $generation): void
    {
        if ($generation->status === AiGenerationStatus::Pending) {
            return;
        }

        $generation->forceFill([
            'status' => AiGenerationStatus::Rejected,
            'approved_at' => null,
            'approved_by' => null,
        ])->save();
    }

    public function regenerate(AiGeneration $generation, ?User $by = null): void
    {
        $generation->forceFill([
            'status' => AiGenerationStatus::Pending,
            'error' => null,
            'approved_at' => null,
            'approved_by' => null,
        ])->save();

        $this->dispatch((int) $generation->workspace_id, $by);
    }

    /**
     * Bulk actions on a selection query, in chunks of IDs, so 50,000
     * selected rows are never loaded into memory. Always limited to the
     * workspace. (MySQL can't UPDATE a table filtered by a subquery on the
     * same table, hence the chunks.)
     *
     * @param  Builder<AiGeneration>  $selection
     */
    public function approveMany(Builder $selection, int $workspaceId, User $by): int
    {
        return $this->eachChunk($selection, $workspaceId, fn (array $ids): int => AiGeneration::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [AiGenerationStatus::Ready->value, AiGenerationStatus::Rejected->value])
            ->whereNotNull('output')
            ->update([
                'status' => AiGenerationStatus::Approved->value,
                'approved_at' => now(),
                'approved_by' => $by->getKey(),
                'updated_at' => now(),
            ]));
    }

    /**
     * @param  Builder<AiGeneration>  $selection
     */
    public function rejectMany(Builder $selection, int $workspaceId): int
    {
        return $this->eachChunk($selection, $workspaceId, fn (array $ids): int => AiGeneration::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [AiGenerationStatus::Ready->value, AiGenerationStatus::Approved->value])
            ->update([
                'status' => AiGenerationStatus::Rejected->value,
                'approved_at' => null,
                'approved_by' => null,
                'updated_at' => now(),
            ]));
    }

    /**
     * @param  Builder<AiGeneration>  $selection
     */
    public function regenerateMany(Builder $selection, int $workspaceId, ?User $by = null): int
    {
        $count = $this->eachChunk($selection, $workspaceId, fn (array $ids): int => AiGeneration::query()
            ->whereIn('id', $ids)
            ->where('status', '!=', AiGenerationStatus::Pending->value)
            ->update([
                'status' => AiGenerationStatus::Pending->value,
                'error' => null,
                'approved_at' => null,
                'approved_by' => null,
                'updated_at' => now(),
            ]));

        if ($count > 0) {
            $this->dispatch($workspaceId, $by);
        }

        return $count;
    }

    /**
     * @param  Builder<AiGeneration>  $selection
     * @param  callable(list<int>): int  $callback
     */
    protected function eachChunk(Builder $selection, int $workspaceId, callable $callback): int
    {
        $affected = 0;

        (clone $selection)
            // The table's eager loads need columns we don't select here.
            ->setEagerLoads([])
            ->where('ai_generations.workspace_id', $workspaceId)
            ->select('ai_generations.id')
            ->reorder()
            ->chunkById(1000, function ($chunk) use ($callback, &$affected): void {
                $affected += $callback($chunk->pluck('id')->map(fn ($id): int => (int) $id)->all());
            }, 'ai_generations.id', 'id');

        return $affected;
    }

    /**
     * Approved AI content for a lead in a campaign, keyed by step id then
     * variable name, e.g. [12 => ['ai_first_line' => '...']].
     *
     * @return array<int, array<string, string>>
     */
    public function approvedForLead(Campaign $campaign, Lead $lead): array
    {
        return AiGeneration::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('lead_id', $lead->getKey())
            ->where('status', AiGenerationStatus::Approved->value)
            ->get(['id', 'campaign_step_id', 'type', 'output'])
            ->groupBy('campaign_step_id')
            ->map(fn (Collection $rows): array => $rows
                ->mapWithKeys(fn (AiGeneration $row): array => [$row->type->variable() => (string) $row->output])
                ->all())
            ->all();
    }

    /**
     * Template variables for approved content. In HTML mode the full email
     * becomes escaped paragraphs.
     *
     * @param  array<string, string>  $approved
     * @return array<string, string|HtmlString>
     */
    public static function variables(array $approved, bool $html): array
    {
        $variables = [];

        foreach (AiContentType::cases() as $type) {
            $value = $approved[$type->variable()] ?? '';

            if ($html && $type === AiContentType::EmailBody && $value !== '') {
                $value = new HtmlString(collect(preg_split("/\n\s*\n/", $value) ?: [])
                    ->map(fn (string $paragraph): string => '<p>'.nl2br(e(trim($paragraph)), false).'</p>')
                    ->implode("\n"));
            }

            $variables[$type->variable()] = $value;
        }

        return $variables;
    }

    /**
     * How many of the audience lack approved content for a step and type.
     */
    public function leadsWithoutApproved(Campaign $campaign, CampaignStep $step, AiContentType $type): int
    {
        return $this->audience->query($campaign)
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('ai_generations')
                ->whereColumn('ai_generations.lead_id', 'leads.id')
                ->where('ai_generations.campaign_step_id', $step->getKey())
                ->where('ai_generations.type', $type->value)
                ->where('ai_generations.status', AiGenerationStatus::Approved->value))
            ->count();
    }

    /**
     * @return array<string, int> Generation counts per status
     */
    public static function counts(int $workspaceId, ?int $campaignId = null): array
    {
        return AiGeneration::query()
            ->where('workspace_id', $workspaceId)
            ->when($campaignId, fn (Builder $query) => $query->where('campaign_id', $campaignId))
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }
}
