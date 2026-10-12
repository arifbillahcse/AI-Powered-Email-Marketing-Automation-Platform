<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Pages\Warmup;
use App\Jobs\ProcessWarmupInbox;
use App\Jobs\SendCampaignEmail;
use App\Jobs\SendWarmupEmail;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\InboxThread;
use App\Models\WarmupMessage;
use App\Services\Inbox\InboundMailProcessor;
use App\Services\Mail\Imap\ImapConnector;
use App\Services\Warmup\WarmupHealth;
use App\Services\Warmup\WarmupPlanner;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\ScriptedImapStream;

beforeEach(function () {
    config(['modules.warmup.enabled' => true]);
});

function warmupMailbox(string $email, array $attributes = []): EmailAccount
{
    return EmailAccount::factory()->create(array_merge([
        'email' => $email,
        'from_name' => 'Arif Billah',
        'warmup_enabled' => true,
    ], $attributes));
}

/**
 * A header-only FETCH response carrying an X-Warmup token.
 *
 * @return list<string|array{literal: string}>
 */
function warmupHeaderResponse(string $tag, int $uid, string $token): array
{
    $literal = "X-Warmup: {$token}\r\n\r\n";

    return [
        '* 1 FETCH (UID '.$uid.' BODY[HEADER.FIELDS (X-WARMUP)] {'.strlen($literal).'}',
        ['literal' => $literal],
        ')',
        "{$tag} OK FETCH completed",
    ];
}

it('ramps the daily volume up to the mailbox target', function () {
    $mailbox = warmupMailbox('arif@softorio.com', ['warmup_daily_target' => 20]);
    $planner = app(WarmupPlanner::class);

    expect($planner->day($mailbox))->toBe(1)
        ->and($planner->quota($mailbox))->toBe(2);

    $mailbox->forceFill(['warmup_started_at' => now()->subDays(3)])->save();
    expect($planner->day($mailbox))->toBe(4)
        ->and($planner->quota($mailbox))->toBe(8);

    $mailbox->forceFill(['warmup_started_at' => now()->subDays(30)])->save();
    expect($planner->quota($mailbox))->toBe(20);
});

it('starts the ramp when warmup is switched on and resets it when switched off', function () {
    $mailbox = EmailAccount::factory()->create();
    expect($mailbox->warmup_started_at)->toBeNull();

    $mailbox->update(['warmup_enabled' => true]);
    expect($mailbox->refresh()->warmup_started_at)->not->toBeNull();

    $mailbox->update(['warmup_enabled' => false]);
    expect($mailbox->refresh()->warmup_started_at)->toBeNull();
});

it('pairs mailboxes on different domains and prefers partners not emailed today', function () {
    $sender = warmupMailbox('arif@softorio.com');
    warmupMailbox('sales@softorio.com');
    $first = warmupMailbox('ops@hostorio.com');
    $second = warmupMailbox('hello@acme.test');
    warmupMailbox('off@other.test', ['warmup_enabled' => false]);

    WarmupMessage::query()->create(['token' => 't1', 'sender_id' => $sender->id, 'recipient_id' => $first->id, 'subject' => 'Hi']);

    expect(app(WarmupPlanner::class)->partner($sender)?->id)->toBe($second->id);

    WarmupMessage::query()->create(['token' => 't2', 'sender_id' => $sender->id, 'recipient_id' => $second->id, 'subject' => 'Hi']);

    // Everyone was emailed today: any partner on another domain will do.
    expect(app(WarmupPlanner::class)->partner($sender)?->id)->toBeIn([$first->id, $second->id]);
});

it('keeps the pool inside the workspace when configured', function () {
    config(['outreach.warmup.pool' => 'workspace']);
    $sender = warmupMailbox('arif@softorio.com');
    warmupMailbox('ops@hostorio.com');

    expect(app(WarmupPlanner::class)->partner($sender))->toBeNull();
});

it('sends a plain warmup email with a tracking token and no links', function () {
    $transport = fakeTransport();
    $sender = warmupMailbox('arif@softorio.com');
    $partner = warmupMailbox('ops@hostorio.com', ['from_name' => 'Asma Akter']);

    SendWarmupEmail::dispatchSync($sender->id);

    $message = WarmupMessage::sole();
    $email = $transport->sent[0];

    expect($message)
        ->sender_id->toBe($sender->id)
        ->recipient_id->toBe($partner->id)
        ->status->toBe('sent')
        ->sent_at->not->toBeNull()
        ->and($email->getTo()[0]->getAddress())->toBe('ops@hostorio.com')
        ->and($email->getHeaders()->get('X-Warmup')->getBodyAsString())->toBe($message->token)
        ->and($email->getSubject())->toBe($message->subject)
        ->and($email->getTextBody())->toContain('Asma')->not->toContain('http')
        ->and($email->getHtmlBody())->toBeNull();
});

it('records a failed warmup send on the mailbox', function () {
    fakeTransport('535 Authentication failed', 535);
    $sender = warmupMailbox('arif@softorio.com');
    warmupMailbox('ops@hostorio.com');

    SendWarmupEmail::dispatchSync($sender->id);

    expect(WarmupMessage::sole()->status)->toBe('failed')
        ->and($sender->refresh()->warmup_error)->toContain('535');
});

it('tells the user when there is nobody to warm up with', function () {
    $transport = fakeTransport();
    $sender = warmupMailbox('arif@softorio.com');

    SendWarmupEmail::dispatchSync($sender->id);

    expect($transport->sent)->toBeEmpty()
        ->and($sender->refresh()->warmup_error)->toContain('No warmup partner');
});

it('replies once, in the same thread', function () {
    $transport = fakeTransport();
    $original = warmupMailbox('arif@softorio.com');
    $partner = warmupMailbox('ops@hostorio.com');
    $parent = WarmupMessage::query()->create([
        'token' => 'parent-token', 'sender_id' => $original->id, 'recipient_id' => $partner->id,
        'subject' => 'Quick question', 'message_id' => 'abc@softorio.com', 'sent_at' => now(), 'delivered_at' => now(),
    ]);

    SendWarmupEmail::dispatchSync($partner->id, $parent->id);
    SendWarmupEmail::dispatchSync($partner->id, $parent->id);

    $reply = WarmupMessage::query()->where('parent_id', $parent->id)->sole();
    $email = $transport->sent[0];

    expect($transport->sent)->toHaveCount(1)
        ->and($reply)->sender_id->toBe($partner->id)->recipient_id->toBe($original->id)->depth->toBe(1)
        ->and($email->getSubject())->toBe('Re: Quick question')
        ->and($email->getHeaders()->get('In-Reply-To')->getBodyAsString())->toBe('<abc@softorio.com>')
        ->and($parent->refresh()->replied_at)->not->toBeNull();
});

it('opens, rescues from spam, files and answers warmup emails over IMAP', function () {
    Queue::fake();
    config(['outreach.mailboxes.allow_private_hosts' => true]);
    $sender = warmupMailbox('arif@softorio.com');
    $mailbox = warmupMailbox('ops@hostorio.com', ['warmup_reply_rate' => 100]);
    $spam = WarmupMessage::query()->create(['token' => 'spam-token', 'sender_id' => $sender->id, 'recipient_id' => $mailbox->id, 'subject' => 'Hi', 'message_id' => 'a@softorio.com', 'sent_at' => now()]);
    $inbox = WarmupMessage::query()->create(['token' => 'inbox-token', 'sender_id' => $sender->id, 'recipient_id' => $mailbox->id, 'subject' => 'Hey', 'message_id' => 'b@softorio.com', 'sent_at' => now()]);
    $other = WarmupMessage::query()->create(['token' => 'other-token', 'sender_id' => $mailbox->id, 'recipient_id' => $sender->id, 'subject' => 'Hey', 'sent_at' => now()]);

    $stream = new ScriptedImapStream([
        '* OK IMAP ready',
        'A001 OK LOGIN completed',
        '* LIST (\HasNoChildren) "/" "INBOX"',
        '* LIST (\HasNoChildren \Junk) "/" "[Gmail]/Spam"',
        '* LIST (\HasNoChildren \Sent) "/" "[Gmail]/Sent Mail"',
        'A002 OK LIST completed',
        'A003 OK CREATE completed',
        '* 1 EXISTS',
        'A004 OK [READ-WRITE] SELECT completed',
        '* SEARCH 5',
        'A005 OK SEARCH completed',
        ...warmupHeaderResponse('A006', 5, 'spam-token'),
        'A007 OK STORE completed',
        'A008 OK MOVE completed',
        '* 2 EXISTS',
        'A009 OK [READ-WRITE] SELECT completed',
        '* SEARCH 7 8',
        'A010 OK SEARCH completed',
        ...warmupHeaderResponse('A011', 7, 'inbox-token'),
        'A012 OK STORE completed',
        'A013 OK MOVE completed',
        // Addressed to someone else: left alone.
        ...warmupHeaderResponse('A014', 8, 'other-token'),
    ]);
    app()->instance(ImapConnector::class, $stream->connector());

    ProcessWarmupInbox::dispatchSync($mailbox->id);

    expect($stream->written)->toContain(
        'A002 LIST "" "*"',
        'A003 CREATE "Warmup"',
        'A004 SELECT "[Gmail]/Spam"',
        'A005 UID SEARCH HEADER X-Warmup ""',
        'A006 UID FETCH 5 (BODY.PEEK[HEADER.FIELDS (X-Warmup)])',
        'A007 UID STORE 5 +FLAGS.SILENT (\Seen)',
        'A008 UID MOVE 5 "Warmup"',
        'A009 SELECT "INBOX"',
        'A013 UID MOVE 7 "Warmup"',
    )
        ->and($stream->written)->not->toContain('A015 UID STORE 8 +FLAGS.SILENT (\Seen)')
        ->and($spam->refresh())->delivered_at->not->toBeNull()->landed_in_spam->toBeTrue()
        ->and($inbox->refresh())->delivered_at->not->toBeNull()->landed_in_spam->toBeFalse()
        ->and($other->refresh()->delivered_at)->toBeNull()
        ->and($mailbox->refresh())->warmup_error->toBeNull()->warmup_checked_at->not->toBeNull();

    Queue::assertPushed(SendWarmupEmail::class, 2);
    Queue::assertPushed(SendWarmupEmail::class, fn (SendWarmupEmail $job): bool => $job->senderId === $mailbox->id && $job->parentId === $spam->id);
});

it('files warmup email under INBOX on servers that keep folders there', function () {
    Queue::fake();
    config(['outreach.mailboxes.allow_private_hosts' => true]);
    $mailbox = warmupMailbox('ops@hostorio.com');

    $stream = new ScriptedImapStream([
        '* OK IMAP ready',
        'A001 OK LOGIN completed',
        '* LIST (\HasChildren) "." "INBOX"',
        '* LIST (\HasNoChildren) "." "INBOX.Sent"',
        '* LIST (\HasNoChildren) "." "INBOX.spam"',
        'A002 OK LIST completed',
        'A003 OK CREATE completed',
        'A004 OK SELECT completed',
        '* SEARCH',
        'A005 OK SEARCH completed',
        'A006 OK SELECT completed',
        '* SEARCH',
        'A007 OK SEARCH completed',
    ]);
    app()->instance(ImapConnector::class, $stream->connector());

    ProcessWarmupInbox::dispatchSync($mailbox->id);

    expect($stream->written)->toContain('A003 CREATE "INBOX.Warmup"', 'A004 SELECT "INBOX.spam"', 'A006 SELECT "INBOX"');
});

it('records inbox check failures on the mailbox', function () {
    config(['outreach.mailboxes.allow_private_hosts' => true]);
    $mailbox = warmupMailbox('ops@hostorio.com');

    $stream = new ScriptedImapStream(['* OK IMAP ready', 'A001 NO [AUTHENTICATIONFAILED] Invalid credentials']);
    app()->instance(ImapConnector::class, $stream->connector());

    ProcessWarmupInbox::dispatchSync($mailbox->id);

    expect($mailbox->refresh()->warmup_error)->toStartWith('Inbox check: IMAP login failed');
});

it('queues due warmup sends once and spreads them over the day', function () {
    Queue::fake();
    $a = warmupMailbox('arif@softorio.com');
    warmupMailbox('ops@hostorio.com');
    warmupMailbox('off@acme.test', ['warmup_enabled' => false]);

    $this->artisan('warmup:send')->assertSuccessful();
    $this->artisan('warmup:send')->assertSuccessful();

    Queue::assertPushed(SendWarmupEmail::class, 2);
    expect($a->refresh()->warmup_next_at->isFuture())->toBeTrue();
});

it('stops for the day once the quota is used', function () {
    Queue::fake();
    $a = warmupMailbox('arif@softorio.com');
    $b = warmupMailbox('ops@hostorio.com');
    $b->forceFill(['warmup_next_at' => now()->addHour()])->save();

    foreach (['t1', 't2'] as $token) {
        WarmupMessage::query()->create(['token' => $token, 'sender_id' => $a->id, 'recipient_id' => $b->id, 'subject' => 'Hi']);
    }

    $this->artisan('warmup:send')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($a->refresh()->warmup_next_at->isAfter(now()->endOfDay()))->toBeTrue();
});

it('does nothing while the module is off', function () {
    Queue::fake();
    config(['modules.warmup.enabled' => false]);
    warmupMailbox('arif@softorio.com');
    warmupMailbox('ops@hostorio.com');

    $this->artisan('warmup:send')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('checks only inboxes with warmup emails on the way', function () {
    Queue::fake();
    $a = warmupMailbox('arif@softorio.com');
    $b = warmupMailbox('ops@hostorio.com');
    WarmupMessage::query()->create(['token' => 't1', 'sender_id' => $a->id, 'recipient_id' => $b->id, 'subject' => 'Hi', 'sent_at' => now()]);

    $this->artisan('warmup:sync')->assertSuccessful();

    Queue::assertPushed(ProcessWarmupInbox::class, 1);
    Queue::assertPushed(ProcessWarmupInbox::class, fn (ProcessWarmupInbox $job): bool => $job->emailAccountId === $b->id);
});

it('reports inbox placement per mailbox', function () {
    $a = warmupMailbox('arif@softorio.com');
    $b = warmupMailbox('ops@hostorio.com');

    foreach ([[true, false], [true, false], [true, false], [true, true], [false, false]] as $i => [$delivered, $spam]) {
        WarmupMessage::query()->create([
            'token' => "t{$i}", 'sender_id' => $a->id, 'recipient_id' => $b->id, 'subject' => 'Hi', 'sent_at' => now(),
            'delivered_at' => $delivered ? now() : null, 'landed_in_spam' => $spam, 'replied_at' => $i === 0 ? now() : null,
        ]);
    }

    $report = app(WarmupHealth::class)->forMailboxes([$a->id, $b->id]);

    expect($report[$a->id])->toBe(['sent' => 5, 'delivered' => 4, 'spam' => 1, 'replies' => 1, 'score' => 75])
        ->and($report[$b->id]['score'])->toBeNull()
        ->and(WarmupHealth::color(75))->toBe('warning');
});

it('keeps warmup emails out of the Unibox', function () {
    $transport = fakeTransport();
    ['mailbox' => $mailbox, 'leads' => $leads] = readyCampaign();
    $leads[0]->update(['email' => 'jane@acme.test']);
    SendCampaignEmail::dispatchSync(CampaignLead::sole()->id, $mailbox->id, 1);
    $sent = EmailMessage::sole();
    $partner = warmupMailbox('jane@acme.test');
    WarmupMessage::query()->create(['token' => 'warm-token', 'sender_id' => $partner->id, 'recipient_id' => $mailbox->id, 'subject' => 'Hi', 'sent_at' => now()]);

    $result = app(InboundMailProcessor::class)->process($mailbox, rawEmail([
        'In-Reply-To' => "<{$sent->message_id}>",
        'X-Warmup' => 'warm-token',
    ]));

    expect($result)->toBeNull()
        ->and(InboxThread::count())->toBe(0)
        ->and($transport->sent)->toHaveCount(1);
});

it('shows each mailbox with its warmup day and inbox rate', function () {
    $workspace = actingInWorkspace();
    $mailbox = warmupMailbox('arif@softorio.com', ['workspace_id' => $workspace->id]);
    EmailAccount::factory()->for($workspace)->create(['email' => 'cold@softorio.com']);

    Livewire::test(Warmup::class)
        ->assertSee('How warmup works')
        ->assertSee('Day 1')
        ->assertSee('0 / 2')
        ->assertSee('No data')
        ->assertCanSeeTableRecords([$mailbox]);
});

it('changes warmup settings from the page', function () {
    $workspace = actingInWorkspace();
    $mailbox = EmailAccount::factory()->for($workspace)->create();

    Livewire::test(Warmup::class)
        ->callAction(TestAction::make('warmupSettings')->table($mailbox), data: [
            'warmup_enabled' => true,
            'warmup_daily_target' => 30,
            'warmup_reply_rate' => 40,
        ]);

    expect($mailbox->refresh())
        ->warmup_enabled->toBeTrue()
        ->warmup_daily_target->toBe(30)
        ->warmup_reply_rate->toBe(40)
        ->warmup_started_at->not->toBeNull();
});

it('is read-only for clients', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    $mailbox = EmailAccount::factory()->for($workspace)->create();

    Livewire::test(Warmup::class)
        ->assertActionHidden(TestAction::make('warmupSettings')->table($mailbox));
});

it('is locked when the plan doesn\'t include warmup', function () {
    $plan = billingOn(['modules' => ['ai']]);
    $workspace = actingInWorkspace();
    subscribe($workspace, $plan);

    Livewire::test(Warmup::class)
        ->assertSee('Warmup isn\'t included in your plan')
        ->assertDontSee('How warmup works');
});
