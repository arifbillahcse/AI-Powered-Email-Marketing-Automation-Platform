<?php

namespace App\Services\Warmup;

use App\Models\WarmupMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inbox placement of each mailbox's warmup emails over the last few days:
 * of the emails that arrived, how many landed in the inbox (not spam).
 * Emails not seen by the recipient yet don't count either way.
 */
class WarmupHealth
{
    /**
     * @param  list<int>  $mailboxIds
     * @return array<int, array{sent: int, delivered: int, spam: int, replies: int, score: ?int}>
     */
    public function forMailboxes(array $mailboxIds, int $days = 7): array
    {
        if ($mailboxIds === []) {
            return [];
        }

        $since = now()->subDays($days);
        $sent = fn (): Builder => WarmupMessage::query()
            ->whereIn('sender_id', $mailboxIds)
            ->where('status', 'sent')
            ->where('sent_at', '>=', $since);

        $count = fn (Builder $query): array => $query
            ->selectRaw('sender_id, count(*) as aggregate')
            ->groupBy('sender_id')
            ->pluck('aggregate', 'sender_id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $totals = $count($sent());
        $delivered = $count($sent()->whereNotNull('delivered_at'));
        $spam = $count($sent()->whereNotNull('delivered_at')->where('landed_in_spam', true));
        $replies = $count($sent()->whereNotNull('replied_at'));

        $report = [];

        foreach ($mailboxIds as $id) {
            $arrived = $delivered[$id] ?? 0;
            $inSpam = $spam[$id] ?? 0;

            $report[$id] = [
                'sent' => $totals[$id] ?? 0,
                'delivered' => $arrived,
                'spam' => $inSpam,
                'replies' => $replies[$id] ?? 0,
                'score' => $arrived > 0 ? (int) round(($arrived - $inSpam) / $arrived * 100) : null,
            ];
        }

        return $report;
    }

    public static function color(?int $score): string
    {
        return match (true) {
            $score === null => 'gray',
            $score >= 90 => 'success',
            $score >= 75 => 'warning',
            default => 'danger',
        };
    }
}
