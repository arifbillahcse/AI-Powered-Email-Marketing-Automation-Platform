<?php

namespace App\Services\Agency;

use App\Models\Workspace;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsReport;
use Illuminate\Support\Str;

/**
 * A read-only, branded results page for a client: a secret link anyone
 * with it can open (no login), plus the same numbers by email on a
 * schedule. The link can be replaced at any time, which kills the old one.
 */
class ClientReport
{
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];

    public const FREQUENCIES = ['off' => 'Off', 'weekly' => 'Weekly (Mondays)', 'monthly' => 'Monthly (1st of the month)'];

    public function __construct(
        protected AnalyticsReport $analytics,
        protected Branding $branding,
    ) {}

    public function url(Workspace $workspace): string
    {
        $token = $workspace->report_token;

        return route('reports.show', ['token' => is_string($token) && $token !== '' ? $token : $this->rotate($workspace)]);
    }

    /**
     * A new secret link; the old one stops working.
     */
    public function rotate(Workspace $workspace): string
    {
        $token = Str::random(40);

        $workspace->forceFill(['report_token' => $token, 'report_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function find(string $token): ?Workspace
    {
        if (strlen($token) !== 40) {
            return null;
        }

        return Workspace::query()->where('report_token_hash', hash('sha256', $token))->first();
    }

    /**
     * @return array{workspace: string, brand: ?Brand, period: string, days: int, totals: array<string, int>, rates: array<string, float>, campaigns: list<array<string, mixed>>}
     */
    public function data(Workspace $workspace, int $days = 30): array
    {
        $days = array_key_exists($days, self::PERIODS) ? $days : 30;
        $filters = AnalyticsFilters::fromState($workspace, ['range' => "{$days}d"]);
        $totals = $this->analytics->totals($filters);

        return [
            'workspace' => (string) $workspace->name,
            'brand' => $this->branding->for($workspace),
            'period' => $filters->label(),
            'days' => $days,
            'totals' => $totals,
            'rates' => AnalyticsReport::rates($totals),
            // Each row: name, counts and {metric}_rate percentages.
            'campaigns' => $this->analytics->byCampaign($filters)
                ->sortByDesc('sent')
                ->take(25)
                ->values()
                ->all(),
        ];
    }

    /**
     * Whether the scheduled email is due today (checked once a day).
     */
    public function due(Workspace $workspace): bool
    {
        if (blank($workspace->report_recipients)) {
            return false;
        }

        $today = now($workspace->timezone ?: 'UTC');
        $recent = $workspace->report_last_sent_at !== null && $workspace->report_last_sent_at->isAfter(now()->subHours(20));

        return ! $recent && match ($workspace->report_frequency) {
            'weekly' => $today->isMonday(),
            'monthly' => $today->day === 1,
            default => false,
        };
    }
}
