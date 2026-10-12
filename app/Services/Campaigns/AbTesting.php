<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Filament\App\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\CampaignStepVariant;
use App\Models\Workspace;
use App\Services\Compliance\WorkspaceAlerts;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A/B/n testing of sequence emails. A step's own subject and body are
 * version A; `campaign_step_variants` holds B, C, ... Each lead gets one
 * version (stable per lead and step). Once every version has been sent
 * `ab_min_sends` times, the best by the campaign's metric becomes the
 * winner and the only version sent from then on.
 */
class AbTesting
{
    public const METRICS = [
        'reply' => 'replied_at',
        'open' => 'opened_at',
        'click' => 'clicked_at',
    ];

    public function __construct(
        protected WorkspaceAlerts $alerts,
    ) {}

    /**
     * @return array<string, array{label: string, subject: ?string, body: string}> keyed by label
     */
    public function versions(CampaignStep $step): array
    {
        $versions = ['A' => ['label' => 'A', 'subject' => $step->subject, 'body' => (string) $step->body]];

        foreach ($step->variants()->get() as $variant) {
            $versions[$variant->label] = [
                'label' => $variant->label,
                // A blank subject (or a same-thread follow-up) keeps the step's.
                'subject' => filled($variant->subject) && ! $step->isReplyInThread() ? $variant->subject : $step->subject,
                'body' => $variant->body,
            ];
        }

        return $versions;
    }

    /**
     * The version this lead gets for this step: the winner once there is
     * one, otherwise a stable pick spread evenly across the versions.
     *
     * @return array{label: string, subject: ?string, body: string}
     */
    public function pick(CampaignStep $step, int $campaignLeadId): array
    {
        $versions = $this->versions($step);

        if ($step->ab_winner && isset($versions[$step->ab_winner])) {
            return $versions[$step->ab_winner];
        }

        $labels = array_keys($versions);

        return $versions[$labels[crc32("{$campaignLeadId}:{$step->getKey()}") % count($labels)]];
    }

    /**
     * Results per step and version.
     *
     * @return array<int, array{step: CampaignStep, versions: array<string, array{sent: int, opened: int, clicked: int, replied: int, rate: float}>}> keyed by step id
     */
    public function results(Campaign $campaign): array
    {
        $metric = self::METRICS[$campaign->ab_metric] ?? 'replied_at';
        $steps = $campaign->steps()->with('variants')->orderBy('position')->get()->filter(fn (CampaignStep $step): bool => $step->variants->isNotEmpty());
        $results = [];

        foreach ($steps as $step) {
            $rows = DB::table('email_messages')
                ->where('campaign_step_id', $step->getKey())
                ->whereNotNull('sent_at')
                ->groupBy('variant')
                ->selectRaw('variant, count(*) as sent, count(opened_at) as opened, count(clicked_at) as clicked, count(replied_at) as replied, count('.$metric.') as hits')
                ->get()
                ->keyBy(fn (object $row): string => $row->variant ?? 'A');

            $versions = [];

            foreach (['A', ...$step->variants->pluck('label')->all()] as $label) {
                $row = $rows[$label] ?? null;
                $sent = (int) ($row->sent ?? 0);

                $versions[$label] = [
                    'sent' => $sent,
                    'opened' => (int) ($row->opened ?? 0),
                    'clicked' => (int) ($row->clicked ?? 0),
                    'replied' => (int) ($row->replied ?? 0),
                    'rate' => $sent ? round(((int) $row->hits) / $sent * 100, 1) : 0.0,
                ];
            }

            $results[$step->getKey()] = ['step' => $step, 'versions' => $versions];
        }

        return $results;
    }

    /**
     * Pick winners where every version has enough sends.
     *
     * @return int Winners picked
     */
    public function decide(Campaign $campaign): int
    {
        if (! $campaign->ab_auto_winner || $campaign->status !== CampaignStatus::Active) {
            return 0;
        }

        $picked = 0;

        foreach ($this->results($campaign) as $result) {
            /** @var CampaignStep $step */
            $step = $result['step'];
            $versions = $result['versions'];

            if ($step->ab_winner !== null) {
                continue;
            }

            if (collect($versions)->contains(fn (array $version): bool => $version['sent'] < $campaign->ab_min_sends)) {
                continue;
            }

            // Highest rate wins; on a tie, the earlier version (A first).
            $best = max(array_column($versions, 'rate'));
            $winner = (string) array_key_first(array_filter($versions, fn (array $version): bool => $version['rate'] === $best));

            $step->forceFill(['ab_winner' => $winner, 'ab_decided_at' => now()])->save();
            $picked++;

            $this->alerts->send(
                $campaign->workspace_id,
                "A/B test decided: version {$winner} wins",
                "Email {$step->position} of \"{$campaign->name}\": version {$winner} had the best ".$this->metricLabel($campaign)
                    ." ({$versions[$winner]['rate']}%). From now on every lead gets version {$winner}.",
                $this->url($campaign),
                'Open campaign',
                'success',
            );
        }

        return $picked;
    }

    public function metricLabel(Campaign $campaign): string
    {
        return match ($campaign->ab_metric) {
            'open' => 'open rate',
            'click' => 'click rate',
            default => 'reply rate',
        };
    }

    /**
     * Labels a step can choose as winner: A plus its variants.
     *
     * @return list<string>
     */
    public static function labels(CampaignStep $step): array
    {
        return ['A', ...CampaignStepVariant::query()->where('campaign_step_id', $step->getKey())->orderBy('label')->pluck('label')->all()];
    }

    protected function url(Campaign $campaign): ?string
    {
        try {
            $workspace = Workspace::query()->find($campaign->workspace_id);

            return $workspace ? CampaignResource::getUrl('edit', ['record' => $campaign], panel: 'app', tenant: $workspace) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
