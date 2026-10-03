<?php

namespace App\Services\Analytics;

use App\Enums\DnsCheckStatus;
use App\Enums\EmailAccountStatus;
use App\Models\EmailAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inbox health score (0–100) for each mailbox, from the last 30 days of
 * sending plus its connection and DNS state. Every point lost comes with a
 * sentence saying why, so the score is actionable.
 */
class MailboxHealth
{
    public const DAYS = 30;

    /**
     * @return Collection<int, array{score: int, grade: string, color: string, issues: list<string>, sent: int}> keyed by mailbox id
     */
    public function forWorkspace(int $workspaceId): Collection
    {
        $mailboxes = EmailAccount::query()->where('workspace_id', $workspaceId)->with('sendingDomain')->get();

        $stats = DB::table('email_messages')
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', now()->subDays(self::DAYS))
            ->whereIn('email_account_id', $mailboxes->modelKeys())
            ->groupBy('email_account_id')
            ->selectRaw('email_account_id, count(*) as sent, count(replied_at) as replied, count(bounced_at) as bounced, count(unsubscribed_at) as unsubscribed')
            ->get()
            ->keyBy('email_account_id');

        return $mailboxes->mapWithKeys(fn (EmailAccount $mailbox): array => [
            $mailbox->getKey() => $this->score($mailbox, [
                'sent' => (int) ($stats[$mailbox->getKey()]->sent ?? 0),
                'replied' => (int) ($stats[$mailbox->getKey()]->replied ?? 0),
                'bounced' => (int) ($stats[$mailbox->getKey()]->bounced ?? 0),
                'unsubscribed' => (int) ($stats[$mailbox->getKey()]->unsubscribed ?? 0),
            ]),
        ]);
    }

    /**
     * @param  array{sent: int, replied: int, bounced: int, unsubscribed: int}  $stats  Last 30 days
     * @return array{score: int, grade: string, color: string, issues: list<string>, sent: int}
     */
    public function score(EmailAccount $mailbox, array $stats): array
    {
        $score = 100;
        $issues = [];
        $penalize = function (int $points, string $issue) use (&$score, &$issues): void {
            $score -= $points;
            $issues[] = $issue;
        };

        $sent = $stats['sent'];
        $bounceRate = $sent ? $stats['bounced'] / $sent * 100 : 0;
        $unsubscribeRate = $sent ? $stats['unsubscribed'] / $sent * 100 : 0;

        if ($bounceRate > 2) {
            $penalize((int) min(40, round($bounceRate * 8)), sprintf('Bounce rate %.1f%% (keep it under 2%%: verify your list).', $bounceRate));
        }

        if ($unsubscribeRate > 1) {
            $penalize((int) min(15, round($unsubscribeRate * 5)), sprintf('Unsubscribe rate %.1f%% (tighten targeting or copy).', $unsubscribeRate));
        }

        if ($sent >= 50 && $stats['replied'] === 0) {
            $penalize(10, "No replies from {$sent} emails. Check whether you're landing in spam.");
        }

        if ($mailbox->status === EmailAccountStatus::Error) {
            $penalize(30, 'The mailbox connection is failing. Run "Test connection".');
        }

        if (filled($mailbox->imap_error)) {
            $penalize(10, 'Replies can\'t be read (IMAP error), so sequences won\'t stop on reply.');
        }

        match ($mailbox->sendingDomain?->status) {
            DnsCheckStatus::Fail => $penalize(25, 'SPF, DKIM or DMARC is failing for the sending domain.'),
            DnsCheckStatus::Warning => $penalize(10, 'The sending domain\'s DNS has warnings.'),
            DnsCheckStatus::Pending => $penalize(5, 'The sending domain hasn\'t been checked yet.'),
            null => $penalize(10, 'No sending domain is linked, so SPF/DKIM/DMARC aren\'t checked.'),
            default => null,
        };

        if ($mailbox->daily_limit > 50) {
            $penalize(10, "Daily limit of {$mailbox->daily_limit} is high for cold email (30–50 is safer).");
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'grade' => match (true) {
                $score >= 80 => 'Healthy',
                $score >= 50 => 'Needs attention',
                default => 'At risk',
            },
            'color' => match (true) {
                $score >= 80 => 'success',
                $score >= 50 => 'warning',
                default => 'danger',
            },
            'issues' => $issues,
            'sent' => $sent,
        ];
    }
}
