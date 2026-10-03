<?php

namespace App\Services\Mail\Imap;

use App\Enums\MailEncryption;
use App\Services\Mail\MailboxConnectionException;

/**
 * The small part of IMAP4rev1 (RFC 3501) reply detection needs: log in,
 * select a folder, search by UID and fetch raw messages. Written on top of
 * a plain socket because PHP's IMAP extension is no longer bundled.
 */
class ImapClient
{
    protected int $tag = 0;

    public function __construct(
        protected ImapStream $stream,
    ) {}

    public function login(MailEncryption $encryption, string $username, #[\SensitiveParameter] string $password): void
    {
        $greeting = $this->stream->readLine();

        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new MailboxConnectionException('Unexpected IMAP server greeting.');
        }

        if ($encryption === MailEncryption::Tls) {
            $this->command('STARTTLS', 'The IMAP server doesn\'t support STARTTLS. Try SSL/TLS on port 993.');
            $this->stream->enableTls();
        }

        $this->command(
            'LOGIN '.$this->quote($username).' '.$this->quote($password),
            'IMAP login failed. Check the username and password (many providers need an app password).',
        );
    }

    /**
     * @return array{uid_validity: ?int, uid_next: ?int, exists: int}
     */
    public function select(string $folder = 'INBOX'): array
    {
        $result = ['uid_validity' => null, 'uid_next' => null, 'exists' => 0];

        foreach ($this->command('EXAMINE '.$this->quote($folder), "Couldn't open the {$folder} folder.") as $response) {
            $line = $response['line'];

            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $match)) {
                $result['uid_validity'] = (int) $match[1];
            } elseif (preg_match('/\[UIDNEXT (\d+)\]/i', $line, $match)) {
                $result['uid_next'] = (int) $match[1];
            } elseif (preg_match('/^\* (\d+) EXISTS/i', $line, $match)) {
                $result['exists'] = (int) $match[1];
            }
        }

        return $result;
    }

    /**
     * @return list<int> Matching UIDs, ascending
     */
    public function search(string $criteria): array
    {
        $uids = [];

        foreach ($this->command("UID SEARCH {$criteria}", 'The IMAP search failed.') as $response) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $response['line'], $match)) {
                foreach (preg_split('/\s+/', trim($match[1])) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }

        sort($uids);

        return array_values(array_unique($uids));
    }

    /**
     * The raw message (headers and body), cut at $maxBytes so a huge
     * attachment can't exhaust memory. Null if the message is gone.
     */
    public function fetch(int $uid, int $maxBytes = 262144): ?string
    {
        foreach ($this->command("UID FETCH {$uid} (BODY.PEEK[]<0.{$maxBytes}>)", 'Fetching a message failed.') as $response) {
            if ($response['literal'] !== null && preg_match('/BODY\[\]/i', $response['line'])) {
                return $response['literal'];
            }
        }

        return null;
    }

    public function logout(): void
    {
        try {
            $this->stream->write($this->nextTag().' LOGOUT');
        } catch (MailboxConnectionException) {
            // Already gone.
        }

        $this->stream->close();
    }

    /**
     * Send a command and collect its untagged responses until the tagged
     * completion. Literals ({123}) are read and attached to their line.
     *
     * @return list<array{line: string, literal: ?string}>
     */
    protected function command(string $command, string $failureMessage): array
    {
        $tag = $this->nextTag();
        $this->stream->write("{$tag} {$command}");

        $responses = [];

        for ($i = 0; $i < 100_000; $i++) {
            $line = $this->stream->readLine();

            if (str_starts_with($line, "{$tag} ")) {
                if (preg_match('/^\S+ OK\b/i', $line)) {
                    return $responses;
                }

                throw new MailboxConnectionException($failureMessage);
            }

            $literal = null;

            if (preg_match('/\{(\d+)\}$/', $line, $match)) {
                $literal = $this->stream->read((int) $match[1]);
                // The rest of the response (e.g. " UID 12)") follows the literal.
                $line .= ' '.$this->stream->readLine();
            }

            $responses[] = ['line' => $line, 'literal' => $literal];
        }

        throw new MailboxConnectionException('The IMAP server sent an unexpected response.');
    }

    protected function nextTag(): string
    {
        return 'A'.str_pad((string) ++$this->tag, 3, '0', STR_PAD_LEFT);
    }

    protected function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new MailboxConnectionException('Credentials contain characters IMAP can\'t send.');
        }

        return '"'.addcslashes($value, '"\\').'"';
    }
}
