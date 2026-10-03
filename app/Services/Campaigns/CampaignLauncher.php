<?php

namespace App\Services\Campaigns;

use App\Enums\AiContentType;
use App\Enums\CampaignStatus;
use App\Enums\EmailAccountStatus;
use App\Enums\LeadActivityType;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\User;
use App\Services\Ai\AiGenerationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Launch, pause, resume and stop campaigns, and enroll their audience.
 * The sending engine (Phase 5) works through `campaign_leads`.
 */
class CampaignLauncher
{
    public function __construct(
        protected CampaignAudience $audience,
        protected TemplateRenderer $renderer,
        protected AiGenerationService $ai,
    ) {}

    /**
     * Everything that blocks a launch, as user-facing sentences.
     *
     * @return list<string>
     */
    public function problems(Campaign $campaign): array
    {
        $problems = [];
        $steps = $campaign->steps()->get();

        if ($campaign->is_template) {
            $problems[] = 'Templates can\'t be launched. Create a campaign from it first.';
        }

        if ($steps->isEmpty()) {
            $problems[] = 'Add at least one email to the sequence.';
        } elseif (blank($steps->first()->subject)) {
            $problems[] = 'The first email needs a subject.';
        }

        if ($steps->contains(fn ($step) => blank(strip_tags((string) $step->body)))) {
            $problems[] = 'Every email in the sequence needs a body.';
        }

        if (! $campaign->emailAccounts()->where('status', EmailAccountStatus::Active->value)->exists()) {
            $problems[] = 'Choose at least one active mailbox to send from.';
        }

        if ($campaign->send_days === []) {
            $problems[] = 'Pick at least one day to send on.';
        }

        if (! $campaign->workspace->hasMailingAddress()) {
            $problems[] = 'Add your mailing address in Workspace settings. Anti-spam law requires it in every email.';
        }

        if ($this->audience->count($campaign) === 0) {
            $problems[] = 'The audience is empty. Add lists or segments with leads that aren\'t suppressed.';
        } else {
            array_push($problems, ...$this->aiProblems($campaign, $steps));
        }

        return $problems;
    }

    /**
     * AI variables used without a fallback need approved content for every
     * lead, or those leads would get a half-empty email.
     *
     * @param  Collection<int, CampaignStep>  $steps
     * @return list<string>
     */
    protected function aiProblems(Campaign $campaign, Collection $steps): array
    {
        $problems = [];

        foreach ($steps as $step) {
            $template = ($step->isReplyInThread() ? '' : (string) $step->subject).' '.$step->body;

            foreach ($this->renderer->variablesWithoutFallback($template) as $variable) {
                $type = AiContentType::fromVariable($variable);

                if (! $type) {
                    continue;
                }

                $missing = $this->ai->leadsWithoutApproved($campaign, $step, $type);

                if ($missing > 0) {
                    $leads = $missing === 1 ? '1 lead has' : Number::format($missing).' leads have';
                    $problems[] = "Email {$step->position} uses {{{$variable}}}, but {$leads} no approved AI content for it yet. "
                        ."Generate and approve it with \"AI personalize\", or add a fallback like {{{$variable}|...}}.";
                }
            }
        }

        return $problems;
    }

    /**
     * @return int Leads enrolled
     *
     * @throws CampaignLaunchException
     */
    public function launch(Campaign $campaign, ?User $by = null): int
    {
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Paused], true)) {
            throw new CampaignLaunchException(['Only draft or paused campaigns can be launched.']);
        }

        if ($problems = $this->problems($campaign)) {
            throw new CampaignLaunchException($problems);
        }

        return DB::transaction(function () use ($campaign, $by): int {
            $enrolled = $this->enroll($campaign, $by);

            $campaign->forceFill([
                'status' => CampaignStatus::Active,
                'launched_at' => $campaign->launched_at ?? now(),
                'paused_at' => null,
            ])->save();

            return $enrolled;
        });
    }

    /**
     * Add audience leads that aren't enrolled yet. Safe to call repeatedly
     * (e.g. after importing more leads into the campaign's lists).
     *
     * Done with INSERT ... SELECT, so 50,000 leads take one query. Numbers are
     * inlined as integers (cast here) because PostgreSQL can't infer the
     * type of bound parameters in an INSERT's SELECT list.
     */
    public function enroll(Campaign $campaign, ?User $by = null): int
    {
        $campaignId = (int) $campaign->getKey();
        $workspaceId = (int) $campaign->workspace_id;
        $lastId = (int) DB::table('campaign_leads')->max('id');
        // The app's clock, not the database's (they can differ, and tests
        // travel in time). A typed literal works on PostgreSQL and MySQL.
        $now = "TIMESTAMP '".now()->format('Y-m-d H:i:s')."'";

        $enrolled = DB::table('campaign_leads')->insertOrIgnoreUsing(
            ['campaign_id', 'lead_id', 'status', 'steps_sent', 'next_send_at', 'created_at', 'updated_at'],
            $this->audience->query($campaign)
                ->toBase()
                ->select([])
                ->selectRaw("{$campaignId} as campaign_id, leads.id as lead_id, 'active' as status, 0 as steps_sent, {$now} as next_send_at, {$now} as created_at, {$now} as updated_at"),
        );

        // Timeline entries for the newly enrolled leads only. user_id is left
        // out when unknown: a bare NULL in the SELECT is typed as text on PostgreSQL.
        $columns = ['workspace_id', 'lead_id', 'type', 'description', 'created_at'];
        // The description is inlined, escaped by the driver's quote(): PostgreSQL
        // can't type a bound parameter that only appears in a SELECT list.
        $description = DB::connection()->getPdo()->quote(mb_substr("Added to campaign \"{$campaign->name}\"", 0, 250));
        $select = "{$workspaceId}, lead_id, '".LeadActivityType::AddedToCampaign->value."', {$description}, {$now}";

        if ($by) {
            $columns[] = 'user_id';
            $select .= ', '.(int) $by->getKey();
        }

        DB::table('lead_activities')->insertUsing(
            $columns,
            DB::table('campaign_leads')
                ->where('campaign_id', $campaignId)
                ->where('id', '>', $lastId)
                ->selectRaw($select),
        );

        return $enrolled;
    }

    public function pause(Campaign $campaign): void
    {
        if ($campaign->status === CampaignStatus::Active) {
            $campaign->forceFill(['status' => CampaignStatus::Paused, 'paused_at' => now()])->save();
        }
    }

    public function resume(Campaign $campaign): void
    {
        if ($campaign->status === CampaignStatus::Paused) {
            $campaign->forceFill(['status' => CampaignStatus::Active, 'paused_at' => null])->save();
        }
    }

    public function complete(Campaign $campaign): void
    {
        $campaign->forceFill(['status' => CampaignStatus::Completed, 'completed_at' => now()])->save();
    }
}
