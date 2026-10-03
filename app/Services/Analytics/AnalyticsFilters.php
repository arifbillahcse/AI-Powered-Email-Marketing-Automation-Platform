<?php

namespace App\Services\Analytics;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The period, campaign and mailbox an analytics view covers. Days are the
 * workspace's days: "last 7 days" in Dhaka starts at midnight Dhaka time.
 */
final class AnalyticsFilters
{
    public const RANGES = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'custom' => 'Custom range',
    ];

    public function __construct(
        public readonly int $workspaceId,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $campaignId = null,
        public readonly ?int $mailboxId = null,
        public readonly string $timezone = 'UTC',
    ) {}

    /**
     * From the (unvalidated) dashboard filter form state.
     *
     * @param  array<string, mixed>|null  $state
     */
    public static function fromState(Workspace $workspace, ?array $state): self
    {
        $state ??= [];
        $tz = $workspace->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay();

        [$from, $to] = match ($state['range'] ?? '30d') {
            'today' => [$today, $today->endOfDay()],
            '7d' => [$today->subDays(6), $today->endOfDay()],
            '90d' => [$today->subDays(89), $today->endOfDay()],
            'this_month' => [$today->startOfMonth(), $today->endOfDay()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'custom' => [
                self::date($state['from'] ?? null, $tz) ?? $today->subDays(29),
                (self::date($state['to'] ?? null, $tz) ?? $today)->endOfDay(),
            ],
            default => [$today->subDays(29), $today->endOfDay()],
        };

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return new self(
            workspaceId: (int) $workspace->getKey(),
            from: $from->utc(),
            to: $to->utc(),
            campaignId: self::id($state['campaign_id'] ?? null),
            mailboxId: self::id($state['email_account_id'] ?? null),
            timezone: $tz,
        );
    }

    public function label(): string
    {
        return $this->from->setTimezone($this->timezone)->format('M j, Y').' – '.$this->to->setTimezone($this->timezone)->format('M j, Y');
    }

    protected static function date(mixed $value, string $tz): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr($value, 0, 10), $tz)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    protected static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
