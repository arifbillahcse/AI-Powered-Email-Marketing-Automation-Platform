<?php

use App\Enums\MailEncryption;
use App\Services\Mail\Imap\ImapConnector;
use App\Services\Mail\Imap\ImapProbe;
use App\Services\Mail\Imap\ImapStream;
use App\Services\Mail\MailboxConnectionException;

/**
 * A scripted IMAP server: returns queued lines and records what was written.
 */
function scriptedImap(array $responses): array
{
    $stream = new class($responses) implements ImapStream
    {
        public array $written = [];

        public bool $tls = false;

        public bool $closed = false;

        public function __construct(public array $responses) {}

        public function write(string $line): void
        {
            $this->written[] = $line;
        }

        public function readLine(): string
        {
            return array_shift($this->responses) ?? throw new MailboxConnectionException('closed');
        }

        public function enableTls(): void
        {
            $this->tls = true;
        }

        public function close(): void
        {
            $this->closed = true;
        }
    };

    $connector = new class($stream) extends ImapConnector
    {
        public function __construct(public ImapStream $stream) {}

        public function connect(string $host, int $port, MailEncryption $encryption): ImapStream
        {
            return $this->stream;
        }
    };

    return [new ImapProbe($connector), $stream];
}

it('logs in with quoted credentials and logs out', function () {
    [$probe, $stream] = scriptedImap(['* OK IMAP4rev1 ready', '* CAPABILITY IMAP4rev1', 'A1 OK LOGIN completed']);

    $probe->check('imap.example.com', 993, MailEncryption::Ssl, 'me@example.com', 'pa"ss\\word');

    expect($stream->written)->toBe([
        'A1 LOGIN "me@example.com" "pa\\"ss\\\\word"',
        'A2 LOGOUT',
    ])->and($stream->closed)->toBeTrue();
});

it('upgrades with STARTTLS before logging in', function () {
    [$probe, $stream] = scriptedImap(['* OK ready', 'A0 OK Begin TLS', 'A1 OK logged in']);

    $probe->check('imap.example.com', 143, MailEncryption::Tls, 'me', 'secret');

    expect($stream->tls)->toBeTrue()
        ->and($stream->written[0])->toBe('A0 STARTTLS');
});

it('reports a failed login clearly', function () {
    [$probe, $stream] = scriptedImap(['* OK ready', 'A1 NO [AUTHENTICATIONFAILED] Invalid credentials']);

    expect(fn () => $probe->check('imap.example.com', 993, MailEncryption::Ssl, 'me', 'wrong'))
        ->toThrow(MailboxConnectionException::class, 'IMAP login failed');

    expect($stream->closed)->toBeTrue();
});

it('rejects credentials with line breaks (command injection)', function () {
    [$probe] = scriptedImap(['* OK ready']);

    $probe->check('imap.example.com', 993, MailEncryption::Ssl, 'me', "x\r\nA9 DELETE INBOX");
})->throws(MailboxConnectionException::class);
