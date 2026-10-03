<?php

namespace App\Services\Inbox;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Mail\HostGuard;
use App\Services\Mail\Imap\ImapClient;
use App\Services\Mail\Imap\ImapConnector;
use App\Services\Mail\MailboxConnectionException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads new mail from a mailbox's INBOX over IMAP and hands it to the
 * InboundMailProcessor. Progress is saved after every message (the last
 * UID seen), so a run that stops halfway continues where it left off.
 *
 * Mail is only read (EXAMINE + BODY.PEEK), never marked as read or moved.
 */
class InboxSynchronizer
{
    public function __construct(
        protected ImapConnector $connector,
        protected HostGuard $guard,
        protected InboundMailProcessor $processor,
    ) {}

    /**
     * @return int Emails imported into the Unibox
     *
     * @throws MailboxConnectionException
     */
    public function sync(EmailAccount $mailbox): int
    {
        $this->guard->assertAllowed($mailbox->imap_host);

        $client = new ImapClient($this->connector->connect($mailbox->imap_host, $mailbox->imap_port, $mailbox->imap_encryption));
        $started = microtime(true);
        $imported = 0;

        try {
            $client->login($mailbox->imap_encryption, $mailbox->imapUsername(), $mailbox->imapPassword());
            $folder = $client->select('INBOX');

            foreach ($this->pendingUids($client, $mailbox, $folder) as $uid) {
                if (microtime(true) - $started > (int) config('outreach.inbox.seconds_per_run')) {
                    break;
                }

                $raw = $client->fetch($uid, (int) config('outreach.inbox.max_message_bytes'));

                try {
                    if ($raw !== null && $this->processor->process($mailbox, $raw, $uid)) {
                        $imported++;
                    }
                } catch (Throwable $exception) {
                    // One unreadable email must not block the mailbox forever.
                    report($exception);
                }

                $mailbox->forceFill(['imap_last_uid' => $uid])->save();
            }

            $mailbox->forceFill(['imap_synced_at' => now(), 'imap_error' => null])->save();
        } finally {
            $client->logout();
        }

        return $imported;
    }

    /**
     * UIDs to read this run, oldest first.
     *
     * The first sync (or after the server renumbered the folder) doesn't
     * import the whole mailbox: it starts from the first campaign email
     * this mailbox sent (at most `lookback_days` ago), or from now.
     *
     * @param  array{uid_validity: ?int, uid_next: ?int, exists: int}  $folder
     * @return list<int>
     */
    protected function pendingUids(ImapClient $client, EmailAccount $mailbox, array $folder): array
    {
        $limit = (int) config('outreach.inbox.messages_per_run');
        $fresh = $mailbox->imap_last_uid === null || $mailbox->imap_uid_validity !== $folder['uid_validity'];

        if ($fresh) {
            $firstSent = EmailMessage::query()->where('email_account_id', $mailbox->getKey())->whereNotNull('sent_at')->min('sent_at');
            $baseline = max(0, ($folder['uid_next'] ?? 1) - 1);

            $mailbox->forceFill(['imap_uid_validity' => $folder['uid_validity'], 'imap_last_uid' => $baseline])->save();

            if (! $firstSent || $folder['exists'] === 0) {
                return [];
            }

            $since = CarbonImmutable::parse($firstSent)->max(now()->subDays((int) config('outreach.inbox.lookback_days')));
            $uids = $client->search('SINCE '.$since->format('j-M-Y'));

            if ($uids !== []) {
                $mailbox->forceFill(['imap_last_uid' => $uids[0] - 1])->save();
            }

            return array_slice($uids, 0, $limit);
        }

        if ($folder['exists'] === 0 || ($folder['uid_next'] !== null && $folder['uid_next'] <= $mailbox->imap_last_uid + 1)) {
            return [];
        }

        // "n:*" always includes the newest message, even when it's older than n.
        $uids = array_filter($client->search('UID '.($mailbox->imap_last_uid + 1).':*'), fn (int $uid): bool => $uid > $mailbox->imap_last_uid);

        return array_slice(array_values($uids), 0, $limit);
    }
}
