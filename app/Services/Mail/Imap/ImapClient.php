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
     * Sign in with an OAuth access token (Gmail, Microsoft 365).
     */
    public function loginWithToken(MailEncryption $encryption, string $username, #[\SensitiveParameter] string $accessToken): void
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
            'AUTHENTICATE XOAUTH2 '.self::xoauth2($username, $accessToken),
            'IMAP sign-in with OAuth failed. Reconnect the mailbox.',
        );
    }

    /**
     * The SASL XOAUTH2 initial response.
     */
    public static function xoauth2(string $username, #[\SensitiveParameter] string $accessToken): string
    {
        return base64_encode("user={$username}\x01auth=Bearer {$accessToken}\x01\x01");
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
     * Open a folder for changes (flags, moves). select() opens read-only.
     *
     * @return array{uid_validity: ?int, uid_next: ?int, exists: int}
     */
    public function selectWritable(string $folder): array
    {
        $result = ['uid_validity' => null, 'uid_next' => null, 'exists' => 0];

        foreach ($this->command('SELECT '.$this->quote($folder), "Couldn't open the {$folder} folder.") as $response) {
            if (preg_match('/^\* (\d+) EXISTS/i', $response['line'], $match)) {
                $result['exists'] = (int) $match[1];
            }
        }

        return $result;
    }

    /**
     * Every folder with its attributes (RFC 3501 LIST + RFC 6154 special use,
     * e.g. \Junk for the spam folder).
     *
     * @return list<array{name: string, delimiter: ?string, attributes: list<string>}>
     */
    public function folders(): array
    {
        $folders = [];

        foreach ($this->command('LIST "" "*"', 'Listing folders failed.') as $response) {
            if (! preg_match('/^\* LIST \(([^)]*)\) ("(?:[^"\\\\]|\\\\.)*"|NIL) (.+)$/i', $response['line'], $match)) {
                continue;
            }

            $name = $response['literal'] ?? trim($match[3]);

            if (str_starts_with($name, '"') && str_ends_with($name, '"')) {
                $name = stripcslashes(substr($name, 1, -1));
            }

            $folders[] = [
                'name' => $name,
                'delimiter' => strtoupper($match[2]) === 'NIL' ? null : stripcslashes(substr($match[2], 1, -1)),
                'attributes' => array_values(array_filter(array_map(fn (string $flag): string => strtolower($flag), preg_split('/\s+/', trim($match[1])) ?: []))),
            ];
        }

        return $folders;
    }

    public function createFolder(string $folder): void
    {
        $this->command('CREATE '.$this->quote($folder), "Couldn't create the {$folder} folder.");
    }

    /**
     * The value of one header (e.g. X-Warmup) without downloading the message.
     */
    public function header(int $uid, string $name): ?string
    {
        $name = preg_replace('/[^A-Za-z0-9-]/', '', $name);

        foreach ($this->command("UID FETCH {$uid} (BODY.PEEK[HEADER.FIELDS ({$name})])", 'Fetching a header failed.') as $response) {
            if ($response['literal'] !== null && preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/mi', $response['literal'], $match)) {
                return trim($match[1]);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $flags  e.g. ['\\Seen']
     */
    public function addFlags(int $uid, array $flags): void
    {
        $this->command("UID STORE {$uid} +FLAGS.SILENT (".implode(' ', $flags).')', 'Updating message flags failed.');
    }

    public function move(int $uid, string $folder): void
    {
        $this->command("UID MOVE {$uid} ".$this->quote($folder), "Moving a message to {$folder} failed.");
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

            // A failed XOAUTH2 sends a "+" challenge with the error; answer
            // with an empty line to get the tagged NO.
            if (str_starts_with($line, '+')) {
                $this->stream->write('');

                continue;
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
