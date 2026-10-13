<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\PlacementResult;
use App\Services\Mail\Imap\MailboxImap;
use App\Services\Mail\MailboxConnectionException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks in one seed mailbox (read-only: EXAMINE) for the placement test
 * emails it's waiting for and records whether each is in the inbox or spam.
 */
class CheckPlacementSeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public int $uniqueFor = 300;

    public function __construct(
        public int $seedId,
    ) {
        $this->onQueue('imap');
    }

    public function uniqueId(): string
    {
        return 'placement-'.$this->seedId;
    }

    public function handle(MailboxImap $imap): void
    {
        $seed = EmailAccount::query()->find($this->seedId);

        $pending = PlacementResult::query()
            ->where('seed_id', $this->seedId)
            ->where('status', 'pending')
            ->pluck('id', 'token');

        if (! $seed || $pending->isEmpty()) {
            return;
        }

        try {
            $client = $imap->open($seed);
        } catch (MailboxConnectionException) {
            return; // Tried again on the next run; missing after the wait.
        }

        try {
            $spam = MailboxImap::spamFolder($client->folders());
            $header = (string) config('outreach.placement.header');
            $since = now()->subDays(2)->format('j-M-Y');

            foreach (array_filter(['INBOX' => 'inbox', (string) $spam => 'spam'], fn (string $status, string $folder): bool => $folder !== '', ARRAY_FILTER_USE_BOTH) as $folder => $status) {
                $client->select($folder);

                foreach (array_slice($client->search("SINCE {$since} HEADER {$header} \"\""), -50) as $uid) {
                    $token = $client->header($uid, $header);
                    $id = $token === null ? null : ($pending[mb_substr($token, 0, 40)] ?? null);

                    if ($id !== null) {
                        PlacementResult::query()->whereKey($id)->where('status', 'pending')->update(['status' => $status, 'checked_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
        } catch (MailboxConnectionException) {
            // Partial results are kept; the rest is checked next run.
        } finally {
            $client->logout();
        }
    }
}
