<?php

namespace App\Services\Sending;

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignStatus;
use App\Enums\EmailAccountStatus;
use App\Enums\EmailMessageStatus;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Explains, in plain sentences, whether a campaign can send right now and
 * what is holding it back. Mirrors the SendScheduler's rules: campaign
 * window and daily cap, each mailbox's status, window, daily limit and gap,
 * leads that are due, and whether the background queue is being worked.
 */
class SendingDiagnostics
{
    public const OK = 'ok';

    public const INFO = 'info';

    public const BLOCKER = 'blocker';

    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * @return array{sending: bool, checks: list<array{level: string, message: string}>}
     */
    public function check(Campaign $campaign, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $checks = [];
        $add = function (string $level, string $message) use (&$checks): void {
            $checks[] = ['level' => $level, 'message' => $message];
        };

        if ($campaign->is_template) {
            $add(self::BLOCKER, 'This is a template. Create a campaign from it to send.');

            return ['sending' => false, 'checks' => $checks];
        }

        if ($campaign->status !== CampaignStatus::Active) {
            $add(self::BLOCKER, match ($campaign->status) {
                CampaignStatus::Draft => 'The campaign is a draft. Launch it to start sending.',
                CampaignStatus::Paused => 'The campaign is paused. Resume it to continue sending.',
                default => 'The campaign is finished.',
            });

            return ['sending' => false, 'checks' => $checks];
        }

        $blocked = false;

        // Campaign schedule.
        $schedule = $this->describeWindow($campaign->send_days, $campaign->send_window_start, $campaign->send_window_end, $campaign->timezone);

        if (SendWindow::isOpen($now, $campaign->timezone, $campaign->send_days, $campaign->send_window_start, $campaign->send_window_end)) {
            $add(self::OK, "Inside the campaign's schedule ({$schedule}).");
        } else {
            $blocked = true;
            $next = $this->nextOpening($now, $campaign->timezone, $campaign->send_days, $campaign->send_window_start);
            $add(self::BLOCKER, "Outside the campaign's schedule ({$schedule}).".($next ? ' It opens again '.$next->diffForHumans().' ('.$next->format('D H:i').').' : ''));
        }

        // Campaign daily cap.
        $sentToday = EmailMessage::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('sent_at', '>=', SendWindow::startOfDay($now, $campaign->timezone))
            ->count();

        if ($sentToday >= $campaign->daily_limit) {
            $blocked = true;
            $add(self::BLOCKER, "The campaign's daily limit is reached ({$sentToday} / {$campaign->daily_limit} today).");
        } else {
            $add(self::OK, "{$sentToday} of {$campaign->daily_limit} emails sent today.");
        }

        // Leads.
        $active = CampaignLead::query()->where('campaign_id', $campaign->getKey())->where('status', CampaignLeadStatus::Active->value);
        $due = (clone $active)->where('next_send_at', '<=', $now)->count();

        if ($due > 0) {
            $add(self::OK, "{$due} ".str('lead')->plural($due).' waiting for an email now.');
        } elseif ((clone $active)->exists()) {
            $nextDue = CarbonImmutable::parse((string) (clone $active)->min('next_send_at'));
            $blocked = true;
            $add(self::INFO, 'No email is due right now. The next one is due '.$nextDue->diffForHumans().' (follow-up delays).');
        } else {
            $blocked = true;
            $add(self::INFO, 'Every lead has finished the sequence, replied or stopped. Add new leads to keep sending.');
        }

        // Mailboxes.
        $mailboxes = $campaign->emailAccounts()->get();
        $timezone = Workspace::query()->whereKey($campaign->workspace_id)->value('timezone') ?: 'UTC';
        $readyMailboxes = 0;

        if ($mailboxes->isEmpty()) {
            $blocked = true;
            $add(self::BLOCKER, 'No mailbox is selected to send from (Sending tab).');
        }

        foreach ($mailboxes as $mailbox) {
            $problem = $this->mailboxProblem($mailbox, $now, $timezone);

            if ($problem === null) {
                $readyMailboxes++;
                $add(self::OK, "{$mailbox->email} is ready to send.");
            } else {
                $add($problem['level'], "{$mailbox->email}: {$problem['message']}");
            }
        }

        if ($mailboxes->isNotEmpty() && $readyMailboxes === 0) {
            $blocked = true;
        }

        // Background queue (database driver: the cron works it).
        if ($stuck = $this->stuckJobs($now)) {
            $blocked = true;
            $add(self::BLOCKER, "{$stuck} queued ".str('job')->plural($stuck).' have waited over 5 minutes. The background worker isn\'t running: check the cron job (Deployment guide, step 7).');
        }

        return ['sending' => ! $blocked, 'checks' => $checks];
    }

    /**
     * A short reason the campaign isn't sending now, or null when it can.
     */
    public function headline(Campaign $campaign, ?CarbonInterface $now = null): ?string
    {
        $result = $this->check($campaign, $now);

        if ($result['sending']) {
            return null;
        }

        foreach (['blocker', 'info'] as $level) {
            foreach ($result['checks'] as $check) {
                if ($check['level'] === $level) {
                    return $check['message'];
                }
            }
        }

        return null;
    }

    /**
     * @return array{level: string, message: string}|null
     */
    protected function mailboxProblem(EmailAccount $mailbox, CarbonInterface $now, string $timezone): ?array
    {
        if ($mailbox->status === EmailAccountStatus::Paused) {
            return ['level' => self::BLOCKER, 'message' => 'paused. Resume it on the Email accounts page.'];
        }

        if ($mailbox->status === EmailAccountStatus::Error) {
            return ['level' => self::BLOCKER, 'message' => 'its connection is failing'.($mailbox->last_error ? " ({$mailbox->last_error})" : '').'. Fix it and run "Test connection".'];
        }

        if (! SendWindow::isOpen($now, $timezone, $mailbox->send_days, $mailbox->send_window_start, $mailbox->send_window_end)) {
            return ['level' => self::BLOCKER, 'message' => 'outside its own sending window ('.$this->describeWindow($mailbox->send_days, $mailbox->send_window_start, $mailbox->send_window_end, $timezone).'). Widen it in the mailbox\'s Sending limits, or leave it open all day.'];
        }

        $sentToday = EmailMessage::query()
            ->where('email_account_id', $mailbox->getKey())
            ->whereIn('status', [EmailMessageStatus::Sent->value, EmailMessageStatus::Bounced->value])
            ->where('sent_at', '>=', SendWindow::startOfDay($now, $timezone))
            ->count();

        if ($sentToday >= $mailbox->daily_limit) {
            return ['level' => self::BLOCKER, 'message' => "daily limit reached ({$sentToday} / {$mailbox->daily_limit}). It sends again tomorrow."];
        }

        if ($mailbox->next_send_at && $mailbox->next_send_at->greaterThan($now)) {
            return ['level' => self::INFO, 'message' => 'pausing between emails (random gap of '.$mailbox->min_delay_seconds.'–'.$mailbox->max_delay_seconds.' seconds). Next email '.$mailbox->next_send_at->diffForHumans().'.'];
        }

        return null;
    }

    protected function stuckJobs(CarbonInterface $now): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now->getTimestamp() - 300)
            ->count();
    }

    /**
     * @param  list<int>|null  $days
     */
    protected function describeWindow(?array $days, string $start, string $end, string $timezone): string
    {
        $days = array_map('intval', $days ?? []);
        sort($days);

        $dayText = match (true) {
            $days === [1, 2, 3, 4, 5, 6, 7] => 'every day',
            $days === [1, 2, 3, 4, 5] => 'Mon–Fri',
            $days === [] => 'no days selected',
            default => implode(', ', array_map(fn (int $day): string => self::DAYS[$day] ?? '?', $days)),
        };

        return "{$dayText}, {$start}–{$end} {$timezone}";
    }

    /**
     * @param  list<int>|null  $days
     */
    protected function nextOpening(CarbonInterface $now, string $timezone, ?array $days, string $start): ?CarbonImmutable
    {
        $days = array_map('intval', $days ?? []);
        $local = CarbonImmutable::instance($now)->setTimezone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $start) + [1 => 0]);

        for ($offset = 0; $offset <= 7; $offset++) {
            $candidate = $local->startOfDay()->addDays($offset)->setTime($hour, $minute);

            if (in_array($candidate->isoWeekday(), $days, true) && $candidate->greaterThan($local)) {
                return $candidate;
            }
        }

        return null;
    }
}
