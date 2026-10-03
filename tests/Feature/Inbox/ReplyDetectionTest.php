<?php

use App\Enums\CampaignLeadStatus;
use App\Enums\EmailEventType;
use App\Enums\LeadStatus;
use App\Enums\MailEncryption;
use App\Jobs\SendCampaignEmail;
use App\Jobs\SyncMailboxInbox;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Lead;
use App\Services\Inbox\InboundMailProcessor;
use App\Services\Leads\SuppressionList;
use App\Services\Mail\Imap\ImapConnector;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ScriptedImapStream;

/**
 * A launched campaign whose first email has gone to jane@acme.test.
 *
 * @return array{mailbox: EmailAccount, lead: Lead, sent: EmailMessage, campaignLead: CampaignLead}
 */
function campaignWithSentEmail(array $campaign = []): array
{
    fakeTransport();
    ['mailbox' => $mailbox, 'leads' => $leads] = readyCampaign(1, $campaign);
    $lead = $leads[0];
    $lead->update(['email' => 'jane@acme.test', 'first_name' => 'Jane']);

    SendCampaignEmail::dispatchSync(CampaignLead::sole()->id, $mailbox->id, 1);

    return ['mailbox' => $mailbox, 'lead' => $lead->refresh(), 'sent' => EmailMessage::sole(), 'campaignLead' => CampaignLead::sole()];
}

function processInbound(EmailAccount $mailbox, string $raw): ?InboxMessage
{
    return app(InboundMailProcessor::class)->process($mailbox, $raw);
}

it('matches a reply by In-Reply-To and stops the sequence', function () {
    ['mailbox' => $mailbox, 'lead' => $lead, 'sent' => $sent, 'campaignLead' => $campaignLead] = campaignWithSentEmail();

    $message = processInbound($mailbox, rawEmail(['In-Reply-To' => "<{$sent->message_id}>", 'References' => "<{$sent->message_id}>"]));

    $thread = InboxThread::sole();

    expect($message)->not->toBeNull()
        ->and($message->email_message_id)->toBe($sent->id)
        ->and($message->body)->toStartWith('Sounds interesting, tell me more.')
        ->and($thread)
        ->campaign_lead_id->toBe($campaignLead->id)
        ->unread->toBeTrue()
        ->auto_reply->toBeFalse()
        ->snippet->toBe('Sounds interesting, tell me more.')
        ->and($campaignLead->refresh())
        ->status->toBe(CampaignLeadStatus::Replied)
        ->next_send_at->toBeNull()
        ->replied_at->not->toBeNull()
        ->and($lead->refresh()->status)->toBe(LeadStatus::Replied)
        ->and($sent->refresh()->replied_at)->not->toBeNull()
        ->and(EmailEvent::query()->where('type', EmailEventType::Reply->value)->count())->toBe(1);
});

it('imports the same email only once', function () {
    ['mailbox' => $mailbox, 'sent' => $sent] = campaignWithSentEmail();
    $raw = rawEmail(['In-Reply-To' => "<{$sent->message_id}>"]);

    processInbound($mailbox, $raw);
    processInbound($mailbox, $raw);

    expect(InboxMessage::count())->toBe(1)
        ->and(InboxThread::sole()->message_count)->toBe(1);
});

it('falls back to the sender when reply headers are missing', function () {
    ['mailbox' => $mailbox, 'campaignLead' => $campaignLead] = campaignWithSentEmail();

    processInbound($mailbox, rawEmail(['From' => 'JANE@acme.test', 'Subject' => 'Your email']));

    expect(InboxThread::sole()->campaign_lead_id)->toBe($campaignLead->id);
});

it('ignores mail that has nothing to do with a campaign', function () {
    ['mailbox' => $mailbox] = campaignWithSentEmail();

    expect(processInbound($mailbox, rawEmail(['From' => 'newsletter@shop.test', 'Subject' => 'Big sale'])))->toBeNull()
        ->and(InboxThread::count())->toBe(0)
        ->and(InboxMessage::count())->toBe(0);
});

it('shows out-of-office replies without stopping the sequence', function (array $headers) {
    ['mailbox' => $mailbox, 'lead' => $lead, 'sent' => $sent, 'campaignLead' => $campaignLead] = campaignWithSentEmail();

    processInbound($mailbox, rawEmail(['In-Reply-To' => "<{$sent->message_id}>", ...$headers], "I'm out until Monday."));

    expect(InboxThread::sole()->auto_reply)->toBeTrue()
        ->and(InboxMessage::sole()->auto_reply)->toBeTrue()
        ->and($campaignLead->refresh()->status)->toBe(CampaignLeadStatus::Active)
        ->and($lead->refresh()->status)->toBe(LeadStatus::Contacted)
        ->and($sent->refresh()->replied_at)->toBeNull();
})->with([
    'auto-submitted header' => [['Auto-Submitted' => 'auto-replied', 'Subject' => 'Re: Quick question']],
    'outlook subject' => [['Subject' => 'Automatic reply: Quick question']],
    'german subject' => [['Subject' => 'Abwesenheitsnotiz: Quick question']],
    'precedence' => [['Precedence' => 'auto_reply']],
]);

it('keeps the sequence going when the campaign does not stop on replies', function () {
    ['mailbox' => $mailbox, 'sent' => $sent, 'campaignLead' => $campaignLead] = campaignWithSentEmail(['stop_on_reply' => false]);

    processInbound($mailbox, rawEmail(['In-Reply-To' => "<{$sent->message_id}>"]));

    expect($campaignLead->refresh())
        ->status->toBe(CampaignLeadStatus::Active)
        ->replied_at->not->toBeNull();
});

it('suppresses leads whose email hard-bounces', function () {
    ['mailbox' => $mailbox, 'lead' => $lead, 'sent' => $sent, 'campaignLead' => $campaignLead] = campaignWithSentEmail();

    $dsn = rawEmail([
        'From' => 'Mail Delivery System <MAILER-DAEMON@mx.acme.test>',
        'Subject' => 'Undelivered Mail Returned to Sender',
        'Content-Type' => 'multipart/report; report-type=delivery-status; boundary="b1"',
    ], implode("\n", [
        '--b1',
        'Content-Type: text/plain',
        '',
        'Your message could not be delivered.',
        '--b1',
        'Content-Type: message/delivery-status',
        '',
        'Final-Recipient: rfc822; jane@acme.test',
        'Action: failed',
        'Status: 5.1.1',
        'Diagnostic-Code: smtp; 550 5.1.1 User unknown',
        '--b1',
        'Content-Type: text/rfc822-headers',
        '',
        "Message-ID: <{$sent->message_id}>",
        '--b1--',
    ]));

    expect(processInbound($mailbox, $dsn))->toBeNull()
        ->and($sent->refresh()->bounced_at)->not->toBeNull()
        ->and($campaignLead->refresh()->status)->toBe(CampaignLeadStatus::Bounced)
        ->and($lead->refresh()->status)->toBe(LeadStatus::Bounced)
        ->and(app(SuppressionList::class)->isSuppressed($lead->workspace_id, 'jane@acme.test'))->toBeTrue()
        ->and(InboxThread::count())->toBe(0);
});

it('reads new mail over IMAP and remembers where it stopped', function () {
    config(['outreach.mailboxes.allow_private_hosts' => true]);
    ['mailbox' => $mailbox, 'sent' => $sent] = campaignWithSentEmail();
    $mailbox->forceFill(['imap_encryption' => MailEncryption::Ssl, 'imap_uid_validity' => 7, 'imap_last_uid' => 8])->save();

    $stream = new ScriptedImapStream([
        '* OK IMAP ready',
        'A001 OK LOGIN completed',
        '* 3 EXISTS',
        '* OK [UIDVALIDITY 7] UIDs valid',
        '* OK [UIDNEXT 11] Predicted next UID',
        'A002 OK [READ-ONLY] EXAMINE completed',
        '* SEARCH 8 9 10',
        'A003 OK SEARCH completed',
        ...ScriptedImapStream::fetchResponse('A004', 9, rawEmail(['From' => 'someone@else.test'])),
        ...ScriptedImapStream::fetchResponse('A005', 10, rawEmail(['In-Reply-To' => "<{$sent->message_id}>"])),
    ]);
    app()->instance(ImapConnector::class, $stream->connector());

    SyncMailboxInbox::dispatchSync($mailbox->id);

    expect($stream->written)->toContain('A002 EXAMINE "INBOX"', 'A003 UID SEARCH UID 9:*', 'A004 UID FETCH 9 (BODY.PEEK[]<0.262144>)')
        ->and($stream->written[0])->toStartWith('A001 LOGIN')
        ->and($stream->closed)->toBeTrue()
        ->and(InboxMessage::sole()->imap_uid)->toBe(10)
        ->and($mailbox->refresh())
        ->imap_last_uid->toBe(10)
        ->imap_error->toBeNull()
        ->imap_synced_at->not->toBeNull();
});

it('starts a new mailbox from now instead of importing old mail', function () {
    config(['outreach.mailboxes.allow_private_hosts' => true]);
    ['mailbox' => $mailbox] = readyCampaign(1, launch: false);

    $stream = new ScriptedImapStream([
        '* OK IMAP ready',
        'A001 OK LOGIN completed',
        '* 500 EXISTS',
        '* OK [UIDVALIDITY 3] UIDs valid',
        '* OK [UIDNEXT 501] Predicted next UID',
        'A002 OK EXAMINE completed',
    ]);
    app()->instance(ImapConnector::class, $stream->connector());

    SyncMailboxInbox::dispatchSync($mailbox->id);

    expect($mailbox->refresh())
        ->imap_uid_validity->toBe(3)
        ->imap_last_uid->toBe(500)
        ->and(collect($stream->written)->contains(fn (string $line) => str_contains($line, 'FETCH')))->toBeFalse();
});

it('records IMAP errors on the mailbox', function () {
    config(['outreach.mailboxes.allow_private_hosts' => true]);
    ['mailbox' => $mailbox] = readyCampaign(1, launch: false);

    $stream = new ScriptedImapStream(['* OK IMAP ready', 'A001 NO [AUTHENTICATIONFAILED] Invalid credentials']);
    app()->instance(ImapConnector::class, $stream->connector());

    SyncMailboxInbox::dispatchSync($mailbox->id);

    expect($mailbox->refresh()->imap_error)->toContain('IMAP login failed');
});

it('queues a check for every connected mailbox', function () {
    Queue::fake();
    ['mailbox' => $mailbox] = readyCampaign(1, launch: false);

    $this->artisan('inbox:sync')->assertSuccessful();

    Queue::assertPushedOn('imap', SyncMailboxInbox::class, fn (SyncMailboxInbox $job) => $job->emailAccountId === $mailbox->id);
});
