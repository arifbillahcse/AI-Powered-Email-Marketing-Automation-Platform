<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\EmailAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Campaign analytics, all from `email_messages`: one row per email sent, with
 * the time it was first opened, clicked, replied to, bounced or unsubscribed
 * from. Each of those is set by the matching event (EngagementRecorder,
 * reply detection), so the numbers always match the event log.
 *
 * Totals and breakdowns count the emails *sent* in the period and what
 * happened to them since ("of the 1,000 emails sent this week, 52 got a
 * reply"), so rates are never above 100%.
 */
class AnalyticsReport
{
    public const METRICS = ['sent', 'opened', 'clicked', 'replied', 'bounced', 'unsubscribed'];

    /**
     * @return array{sent: int, opened: int, clicked: int, replied: int, bounced: int, unsubscribed: int}
     */
    public function totals(AnalyticsFilters $filters): array
    {
        $row = $this->messages($filters)->selectRaw($this->countColumns())->first();

        return $this->counts($row);
    }

    /**
     * Rates as percentages of emails sent (0 when nothing was sent).
     *
     * @param  array<string, int>  $counts
     * @return array{open: float, click: float, reply: float, bounce: float, unsubscribe: float}
     */
    public static function rates(array $counts): array
    {
        $sent = max(0, (int) ($counts['sent'] ?? 0));
        $rate = fn (string $metric): float => $sent === 0 ? 0.0 : round(((int) ($counts[$metric] ?? 0)) / $sent * 100, 1);

        return [
            'open' => $rate('opened'),
            'click' => $rate('clicked'),
            'reply' => $rate('replied'),
            'bounce' => $rate('bounced'),
            'unsubscribe' => $rate('unsubscribed'),
        ];
    }

    /**
     * Activity per day (UTC dates): emails sent, and first opens, clicks and
     * replies, each on the day it happened. Every day in the period is
     * present, with zeros on quiet days.
     *
     * @return array<string, array{sent: int, opened: int, clicked: int, replied: int}>
     */
    public function daily(AnalyticsFilters $filters): array
    {
        $days = [];
        $day = $filters->from->startOfDay();

        while ($day->lessThanOrEqualTo($filters->to) && count($days) < 400) {
            $days[$day->toDateString()] = ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0];
            $day = $day->addDay();
        }

        foreach (['sent' => 'sent_at', 'opened' => 'opened_at', 'clicked' => 'clicked_at', 'replied' => 'replied_at'] as $metric => $column) {
            $rows = $this->scoped($filters)
                ->whereBetween($column, [$filters->from, $filters->to])
                ->selectRaw("date({$column}) as day, count(*) as total")
                ->groupByRaw("date({$column})")
                ->get();

            foreach ($rows as $row) {
                $key = CarbonImmutable::parse((string) $row->day)->toDateString();

                if (isset($days[$key])) {
                    $days[$key][$metric] = (int) $row->total;
                }
            }
        }

        return $days;
    }

    /**
     * @return Collection<int, array<string, mixed>> keyed by campaign id
     */
    public function byCampaign(AnalyticsFilters $filters): Collection
    {
        $rows = $this->grouped($filters, 'campaign_id');
        $names = Campaign::query()->whereIn('id', $rows->keys())->pluck('name', 'id');

        return $rows->map(fn (array $row, int $id): array => ['name' => $names[$id] ?? 'Deleted campaign', ...$row]);
    }

    /**
     * Per sequence step of one campaign.
     *
     * @return Collection<int, array<string, mixed>> keyed by step position
     */
    public function byStep(AnalyticsFilters $filters): Collection
    {
        if (! $filters->campaignId) {
            return collect();
        }

        $rows = $this->grouped($filters, 'step_position');
        $subjects = CampaignStep::query()->where('campaign_id', $filters->campaignId)->pluck('subject', 'position');

        return $rows->sortKeys()->map(fn (array $row, int $position): array => [
            'name' => "Email {$position}".(filled($subjects[$position] ?? null) ? ': '.$subjects[$position] : ' (same thread)'),
            ...$row,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>> keyed by mailbox id
     */
    public function byMailbox(AnalyticsFilters $filters): Collection
    {
        $rows = $this->grouped($filters, 'email_account_id');
        $emails = EmailAccount::query()->whereIn('id', $rows->keys())->pluck('email', 'id');

        return $rows->map(fn (array $row, int $id): array => ['name' => $emails[$id] ?? 'Deleted mailbox', ...$row]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function grouped(AnalyticsFilters $filters, string $column): Collection
    {
        return $this->messages($filters)
            ->whereNotNull($column)
            ->selectRaw("{$column} as group_key, ".$this->countColumns())
            ->groupBy($column)
            ->get()
            ->mapWithKeys(function (object $row): array {
                $counts = $this->counts($row);

                return [(int) $row->group_key => [...$counts, ...array_combine(
                    array_map(fn (string $rate): string => "{$rate}_rate", array_keys(self::rates($counts))),
                    array_values(self::rates($counts)),
                )]];
            });
    }

    /**
     * Emails sent in the period (the cohort every metric is counted over).
     */
    protected function messages(AnalyticsFilters $filters): Builder
    {
        return $this->scoped($filters)->whereBetween('sent_at', [$filters->from, $filters->to]);
    }

    protected function scoped(AnalyticsFilters $filters): Builder
    {
        return DB::table('email_messages')
            ->where('workspace_id', $filters->workspaceId)
            ->whereNotNull('sent_at')
            ->when($filters->campaignId, fn (Builder $query, int $id) => $query->where('campaign_id', $id))
            ->when($filters->mailboxId, fn (Builder $query, int $id) => $query->where('email_account_id', $id));
    }

    protected function countColumns(): string
    {
        return 'count(*) as sent, count(opened_at) as opened, count(clicked_at) as clicked, '
            .'count(replied_at) as replied, count(bounced_at) as bounced, count(unsubscribed_at) as unsubscribed';
    }

    /**
     * @return array{sent: int, opened: int, clicked: int, replied: int, bounced: int, unsubscribed: int}
     */
    protected function counts(?object $row): array
    {
        $counts = [];

        foreach (self::METRICS as $metric) {
            $counts[$metric] = (int) ($row->{$metric} ?? 0);
        }

        return $counts;
    }
}
