<?php

namespace App\Services\Mail\Imap;

use App\Enums\MailEncryption;
use App\Services\Mail\MailboxConnectionException;

/**
 * Verifies IMAP credentials by logging in and out. Full mailbox reading
 * (for reply detection) arrives in Phase 7.
 */
class ImapProbe
{
    public function __construct(
        protected ImapConnector $connector,
    ) {}

    public function check(string $host, int $port, MailEncryption $encryption, string $username, #[\SensitiveParameter] string $password): void
    {
        $stream = $this->connector->connect($host, $port, $encryption);

        try {
            $greeting = $stream->readLine();

            if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
                throw new MailboxConnectionException('Unexpected IMAP server greeting.');
            }

            if ($encryption === MailEncryption::Tls) {
                $this->command($stream, 'A0', 'STARTTLS', 'The IMAP server doesn\'t support STARTTLS. Try SSL/TLS on port 993.');
                $stream->enableTls();
            }

            $this->command(
                $stream,
                'A1',
                'LOGIN '.$this->quote($username).' '.$this->quote($password),
                'IMAP login failed. Check the username and password (many providers need an app password).',
            );

            $stream->write('A2 LOGOUT');
        } finally {
            $stream->close();
        }
    }

    /**
     * Send a tagged command and wait for its tagged response.
     */
    protected function command(ImapStream $stream, string $tag, string $command, string $failureMessage): void
    {
        $stream->write("{$tag} {$command}");

        for ($i = 0; $i < 100; $i++) {
            $line = $stream->readLine();

            if (str_starts_with($line, "{$tag} OK")) {
                return;
            }

            if (str_starts_with($line, "{$tag} NO") || str_starts_with($line, "{$tag} BAD")) {
                throw new MailboxConnectionException($failureMessage);
            }
        }

        throw new MailboxConnectionException('The IMAP server sent an unexpected response.');
    }

    protected function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new MailboxConnectionException('Credentials contain characters IMAP can\'t send.');
        }

        return '"'.addcslashes($value, '"\\').'"';
    }
}
