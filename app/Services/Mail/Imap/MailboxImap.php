<?php

namespace App\Services\Mail\Imap;

use App\Models\EmailAccount;
use App\Services\Mail\HostGuard;
use App\Services\Mail\MailboxConnectionException;
use App\Services\Mail\OAuth\MailboxOAuth;
use Illuminate\Support\Str;

/**
 * Signs in to a mailbox's IMAP server (through HostGuard), and finds its
 * special folders. Shared by warmup and inbox placement tests.
 */
class MailboxImap
{
    public function __construct(
        protected ImapConnector $connector,
        protected HostGuard $guard,
    ) {}

    /**
     * A signed-in client. Call logout() when done.
     *
     * @throws MailboxConnectionException
     */
    public function open(EmailAccount $mailbox): ImapClient
    {
        $this->guard->assertAllowed($mailbox->imap_host);

        $client = new ImapClient($this->connector->connect($mailbox->imap_host, $mailbox->imap_port, $mailbox->imap_encryption));

        try {
            $mailbox->usesOAuth()
                ? $client->loginWithToken($mailbox->imap_encryption, $mailbox->imapUsername(), app(MailboxOAuth::class)->accessToken($mailbox))
                : $client->login($mailbox->imap_encryption, $mailbox->imapUsername(), $mailbox->imapPassword());
        } catch (MailboxConnectionException $exception) {
            $client->logout();

            throw $exception;
        }

        return $client;
    }

    /**
     * The spam folder: flagged \Junk by the server, or else a known name.
     *
     * @param  list<array{name: string, delimiter: ?string, attributes: list<string>}>  $folders
     */
    public static function spamFolder(array $folders): ?string
    {
        foreach ($folders as $folder) {
            if (in_array('\\junk', $folder['attributes'], true)) {
                return $folder['name'];
            }
        }

        $names = array_map(fn (string $name): string => Str::lower($name), config('outreach.warmup.spam_folders'));

        foreach ($folders as $folder) {
            if (in_array(Str::lower($folder['name']), $names, true) && ! in_array('\\noselect', $folder['attributes'], true)) {
                return $folder['name'];
            }
        }

        return null;
    }
}
