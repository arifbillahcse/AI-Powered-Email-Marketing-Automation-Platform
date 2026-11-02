<?php

use App\Enums\MailEncryption;
use App\Jobs\SyncMailboxInbox;
use App\Models\EmailAccount;
use App\Services\Mail\Imap\ImapConnector;
use App\Services\Mail\MailboxConnectionException;
use App\Services\Mail\MailboxConnectionTester;
use App\Services\Mail\MailboxTransportFactory;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\ScriptedImapStream;

/**
 * A mailbox whose password was saved under another APP_KEY: the stored value
 * can't be decrypted.
 */
function mailboxWithUnreadablePassword(): EmailAccount
{
    $mailbox = EmailAccount::factory()->for(actingInWorkspace())->create(['imap_encryption' => MailEncryption::Ssl]);
    DB::table('email_accounts')->where('id', $mailbox->id)->update(['smtp_password' => 'not-a-valid-payload', 'imap_password' => null]);

    return $mailbox->refresh();
}

it('turns an unreadable saved password into a mailbox error, not a crash', function () {
    $mailbox = mailboxWithUnreadablePassword();

    expect(fn () => $mailbox->smtpPassword())->toThrow(MailboxConnectionException::class, 'enter its password again')
        ->and(fn () => $mailbox->imapPassword())->toThrow(MailboxConnectionException::class, 'enter its password again')
        ->and(fn () => app(MailboxTransportFactory::class)->make($mailbox))->toThrow(MailboxConnectionException::class);
});

it('reports it when testing the connection', function () {
    config(['outreach.mailboxes.allow_private_hosts' => true]);
    $mailbox = mailboxWithUnreadablePassword();

    $result = app(MailboxConnectionTester::class)->testAndRecord($mailbox);

    expect($result->passed())->toBeFalse()
        ->and($result->error())->toContain('enter its password again')
        ->and($mailbox->refresh()->last_error)->toContain('enter its password again');
});

it('records it on the mailbox when checking for replies', function () {
    config(['outreach.mailboxes.allow_private_hosts' => true]);
    $mailbox = mailboxWithUnreadablePassword();
    $stream = new ScriptedImapStream(['* OK IMAP ready']);
    app()->instance(ImapConnector::class, $stream->connector());

    SyncMailboxInbox::dispatchSync($mailbox->id);

    expect($mailbox->refresh()->imap_error)->toContain('enter its password again');
});

it('still reads a normal saved password', function () {
    $mailbox = EmailAccount::factory()->for(actingInWorkspace())->create(['smtp_password' => 'secret-1', 'imap_password' => null]);

    expect($mailbox->smtpPassword())->toBe('secret-1')
        ->and($mailbox->imapPassword())->toBe('secret-1');
});
