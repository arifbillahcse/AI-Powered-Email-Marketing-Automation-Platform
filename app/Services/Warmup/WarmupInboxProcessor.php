<?php

namespace App\Services\Warmup;

use App\Jobs\SendWarmupEmail;
use App\Models\EmailAccount;
use App\Models\WarmupMessage;
use App\Services\Mail\Imap\ImapClient;
use App\Services\Mail\Imap\MailboxImap;
use App\Services\Mail\MailboxConnectionException;
use Illuminate\Support\Str;

/**
 * Handles the warmup emails that reached a mailbox, the way an engaged
 * reader would: rescue them from spam, mark them read, file them in the
 * warmup folder (out of the user's way) and sometimes reply.
 *
 * Only emails whose X-Warmup token matches a warmup email sent to this
 * mailbox are touched; everything else is left exactly as it is.
 */
class WarmupInboxProcessor
{
    public function __construct(
        protected MailboxImap $imap,
    ) {}

    /**
     * @return int Warmup emails handled
     *
     * @throws MailboxConnectionException
     */
    public function process(EmailAccount $mailbox): int
    {
        $client = $this->imap->open($mailbox);
        $started = microtime(true);
        $handled = 0;

        try {
            $folders = $client->folders();
            $spam = MailboxImap::spamFolder($folders);
            $archive = $this->archiveFolder($client, $folders);
            $header = (string) config('outreach.warmup.header');
            $limit = (int) config('outreach.warmup.messages_per_run');

            // Spam first: those are the ones that need rescuing.
            foreach (array_filter([$spam, 'INBOX']) as $folder) {
                $inSpam = $folder === $spam;
                $client->selectWritable($folder);

                foreach (array_slice($client->search('HEADER '.$header.' ""'), 0, $limit) as $uid) {
                    if (microtime(true) - $started > (int) config('outreach.warmup.seconds_per_run')) {
                        break 2;
                    }

                    $token = $client->header($uid, $header);
                    $message = $token === null ? null : WarmupMessage::query()
                        ->where('token', mb_substr($token, 0, 40))
                        ->where('recipient_id', $mailbox->getKey())
                        ->first();

                    if (! $message) {
                        continue;
                    }

                    $this->delivered($mailbox, $message, $inSpam);
                    $client->addFlags($uid, ['\\Seen']);

                    // Moving out of spam is the "not spam" signal providers learn from.
                    $target = $archive ?? ($inSpam ? 'INBOX' : null);

                    if ($target !== null) {
                        try {
                            $client->move($uid, $target);
                        } catch (MailboxConnectionException) {
                            // No MOVE support: the email stays where it is.
                        }
                    }

                    $handled++;
                }
            }

            $mailbox->forceFill(['warmup_checked_at' => now(), 'warmup_error' => null])->save();
        } finally {
            $client->logout();
        }

        return $handled;
    }

    protected function delivered(EmailAccount $mailbox, WarmupMessage $message, bool $inSpam): void
    {
        $first = WarmupMessage::query()
            ->whereKey($message->getKey())
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now(), 'landed_in_spam' => $inSpam, 'updated_at' => now()]);

        if ($first !== 1 || $message->status !== 'sent') {
            return;
        }

        $replies = $mailbox->warmup_enabled
            && $message->depth < (int) config('outreach.warmup.max_thread_depth')
            && random_int(1, 100) <= (int) $mailbox->warmup_reply_rate;

        if ($replies) {
            [$min, $max] = config('outreach.warmup.reply_delay_minutes');

            SendWarmupEmail::dispatch($mailbox->getKey(), $message->getKey())
                ->delay(now()->addMinutes(random_int((int) $min, (int) $max)));
        }
    }

    /**
     * The folder warmup emails are filed in, created when missing. Servers
     * that keep every folder under INBOX (cPanel's Dovecot: "INBOX.Sent")
     * get "INBOX.Warmup". Null if it can't be created.
     *
     * @param  list<array{name: string, delimiter: ?string, attributes: list<string>}>  $folders
     */
    protected function archiveFolder(ImapClient $client, array $folders): ?string
    {
        $name = (string) config('outreach.warmup.folder');

        if ($name === '') {
            return null;
        }

        $inbox = collect($folders)->first(fn (array $folder): bool => Str::upper($folder['name']) === 'INBOX');
        $delimiter = $inbox['delimiter'] ?? null;
        $others = collect($folders)->reject(fn (array $folder): bool => Str::upper($folder['name']) === 'INBOX');

        if ($delimiter !== null && $others->isNotEmpty() && $others->every(fn (array $folder): bool => Str::startsWith(Str::upper($folder['name']), 'INBOX'.$delimiter))) {
            $name = 'INBOX'.$delimiter.$name;
        }

        foreach ($folders as $folder) {
            if (Str::lower($folder['name']) === Str::lower($name)) {
                return $folder['name'];
            }
        }

        try {
            $client->createFolder($name);
        } catch (MailboxConnectionException) {
            return null;
        }

        return $name;
    }
}
