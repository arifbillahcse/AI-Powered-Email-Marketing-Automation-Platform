<?php

namespace App\Services\Warmup;

use App\Enums\EmailAccountStatus;
use App\Models\EmailAccount;
use App\Models\WarmupMessage;
use App\Services\Billing\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who warms up, how much today, and with whom.
 *
 * Volume ramps up: `start_per_day` on day 1, plus `increase_per_day` every
 * day after, until the mailbox's daily target. Only new conversations
 * count towards it; replies come on top, like real mail.
 */
class WarmupPlanner
{
    /** @var array<int, bool> */
    protected array $allowed = [];

    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    /**
     * Mailboxes taking part: warmup on, active, workspace not suspended.
     *
     * @return Builder<EmailAccount>
     */
    public function pool(): Builder
    {
        return EmailAccount::query()
            ->where('warmup_enabled', true)
            ->where('status', EmailAccountStatus::Active->value)
            ->whereHas('workspace', fn (Builder $workspace) => $workspace->whereNull('suspended_at'));
    }

    /**
     * The module is on and the workspace's plan includes it.
     */
    public function allowed(EmailAccount|int $workspaceOrMailbox): bool
    {
        $workspaceId = $workspaceOrMailbox instanceof EmailAccount ? $workspaceOrMailbox->workspace_id : $workspaceOrMailbox;

        return $this->allowed[$workspaceId] ??= $this->entitlements->allowsModule($workspaceId, 'warmup');
    }

    public function day(EmailAccount $mailbox): int
    {
        if (! $mailbox->warmup_started_at) {
            return 1;
        }

        return (int) floor($mailbox->warmup_started_at->diffInDays(now())) + 1;
    }

    /**
     * New warmup conversations this mailbox starts today.
     */
    public function quota(EmailAccount $mailbox): int
    {
        $target = min((int) $mailbox->warmup_daily_target, (int) config('outreach.warmup.max_daily_target'));
        $ramp = (int) config('outreach.warmup.start_per_day') + (int) config('outreach.warmup.increase_per_day') * ($this->day($mailbox) - 1);

        return max(0, min($target, $ramp));
    }

    public function startedToday(EmailAccount $mailbox): int
    {
        return WarmupMessage::query()
            ->where('sender_id', $mailbox->getKey())
            ->whereNull('parent_id')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * A partner on another domain, preferably one not emailed today.
     */
    public function partner(EmailAccount $sender): ?EmailAccount
    {
        $candidates = $this->pool()
            ->whereKeyNot($sender->getKey())
            ->where('email', 'not like', '%@'.$sender->domain())
            ->when(config('outreach.warmup.pool') === 'workspace', fn (Builder $query) => $query->where('workspace_id', $sender->workspace_id));

        $emailedToday = WarmupMessage::query()
            ->select('recipient_id')
            ->where('sender_id', $sender->getKey())
            ->where('created_at', '>=', now()->startOfDay());

        foreach ([(clone $candidates)->whereNotIn('id', $emailedToday), $candidates] as $query) {
            $partner = $query->inRandomOrder()->limit(10)->get()->first(fn (EmailAccount $mailbox): bool => $this->allowed($mailbox));

            if ($partner) {
                return $partner;
            }
        }

        return null;
    }

    /**
     * When to send the next one: the rest of today's quota spread over the
     * rest of the day (with jitter), or early tomorrow when it's used up.
     */
    public function nextSendAt(int $remaining): CarbonInterface
    {
        if ($remaining <= 0) {
            return now()->addDay()->startOfDay()->addMinutes(random_int(0, 120));
        }

        $minutesLeft = max(1, (int) now()->diffInMinutes(now()->endOfDay()));
        $interval = min(240, max(10, intdiv($minutesLeft, $remaining)));

        return now()->addMinutes((int) round($interval * random_int(75, 125) / 100));
    }
}
