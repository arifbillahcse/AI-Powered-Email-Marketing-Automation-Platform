<?php

namespace Tests\Fakes;

use App\Enums\MailEncryption;
use App\Services\Mail\Imap\ImapConnector;
use App\Services\Mail\Imap\ImapStream;
use App\Services\Mail\MailboxConnectionException;

/**
 * A scripted IMAP server: replays queued response lines (and literals) in
 * order and records every command written to it.
 */
class ScriptedImapStream implements ImapStream
{
    /** @var list<string> */
    public array $written = [];

    public bool $closed = false;

    /**
     * @param  list<string|array{literal: string}>  $script
     */
    public function __construct(public array $script = []) {}

    /**
     * A FETCH response carrying a raw message as a literal.
     *
     * @return list<string|array{literal: string}>
     */
    public static function fetchResponse(string $tag, int $uid, string $raw): array
    {
        return [
            '* 1 FETCH (UID '.$uid.' BODY[]<0> {'.strlen($raw).'}',
            ['literal' => $raw],
            ')',
            "{$tag} OK FETCH completed",
        ];
    }

    public function connector(): ImapConnector
    {
        return new class($this) extends ImapConnector
        {
            public function __construct(public ImapStream $stream) {}

            public function connect(string $host, int $port, MailEncryption $encryption): ImapStream
            {
                return $this->stream;
            }
        };
    }

    public function write(string $line): void
    {
        $this->written[] = $line;
    }

    public function readLine(): string
    {
        $next = array_shift($this->script);

        if (! is_string($next)) {
            throw new MailboxConnectionException('The IMAP server closed the connection.');
        }

        return $next;
    }

    public function read(int $bytes): string
    {
        $next = array_shift($this->script);

        if (! is_array($next)) {
            throw new MailboxConnectionException('Expected a literal.');
        }

        return substr($next['literal'], 0, $bytes);
    }

    public function enableTls(): void {}

    public function close(): void
    {
        $this->closed = true;
    }
}
