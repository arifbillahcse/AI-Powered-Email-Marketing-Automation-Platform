<?php

use App\Enums\MailEncryption;
use App\Services\Mail\Imap\ImapClient;
use App\Services\Mail\MailboxConnectionException;
use Tests\Fakes\ScriptedImapStream;

it('logs in with STARTTLS and opens the inbox read-only', function () {
    $stream = new ScriptedImapStream([
        '* OK ready',
        'A001 OK Begin TLS',
        'A002 OK Logged in',
        '* 2 EXISTS',
        '* OK [UIDVALIDITY 99] ok',
        '* OK [UIDNEXT 42] ok',
        'A003 OK [READ-ONLY] done',
    ]);
    $client = new ImapClient($stream);

    $client->login(MailEncryption::Tls, 'arif@softorio.com', 'pa"ss');

    expect($client->select())->toBe(['uid_validity' => 99, 'uid_next' => 42, 'exists' => 2])
        ->and($stream->written)->toBe(['A001 STARTTLS', 'A002 LOGIN "arif@softorio.com" "pa\\"ss"', 'A003 EXAMINE "INBOX"']);
});

it('parses search results and literal message bodies', function () {
    $raw = "Subject: Hi\r\n\r\nBody with a line ending in {5}\r\n";
    $stream = new ScriptedImapStream([
        '* SEARCH 12 3 7',
        'A001 OK done',
        ...ScriptedImapStream::fetchResponse('A002', 7, $raw),
    ]);
    $client = new ImapClient($stream);

    expect($client->search('UID 3:*'))->toBe([3, 7, 12])
        ->and($client->fetch(7))->toBe($raw);
});

it('fails clearly when the server says no', function () {
    $client = new ImapClient(new ScriptedImapStream(['* OK ready', 'A001 NO bad password']));

    expect(fn () => $client->login(MailEncryption::Ssl, 'user', 'wrong'))
        ->toThrow(MailboxConnectionException::class, 'IMAP login failed');
});
