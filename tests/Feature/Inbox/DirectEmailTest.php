<?php

use App\Enums\InboxMessageStatus;
use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\InboxThreads\Pages\ViewInboxThread;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Filament\App\Resources\Leads\Pages\ViewLead;
use App\Models\EmailAccount;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Lead;
use App\Models\Workspace;
use App\Services\Inbox\InboundMailProcessor;
use App\Services\Leads\SuppressionList;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * @return array{mailbox: EmailAccount, lead: Lead}
 */
function directEmailSetup(Workspace $workspace): array
{
    return [
        'mailbox' => EmailAccount::factory()->for($workspace)->create(['email' => 'arif@softorio.com', 'from_name' => 'Arif Billah']),
        'lead' => Lead::factory()->for($workspace)->create(['email' => 'jane@acme.test', 'first_name' => 'Jane', 'company' => 'Acme']),
    ];
}

it('sends a one-off email to a single lead', function () {
    $transport = fakeTransport();
    $workspace = actingInWorkspace();
    ['mailbox' => $mailbox, 'lead' => $lead] = directEmailSetup($workspace);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('sendEmail', data: [
            'email_account_id' => $mailbox->id,
            'subject' => 'Idea for {{company}}',
            'body' => "Hi {{first_name|there}},\n\nQuick thought.\n{{sender_first_name}}",
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Email queued');

    $email = $transport->sent[0];
    $thread = InboxThread::sole();

    expect($email->getFrom()[0]->getAddress())->toBe('arif@softorio.com')
        ->and($email->getTo()[0]->getAddress())->toBe('jane@acme.test')
        ->and($email->getSubject())->toBe('Idea for Acme')
        ->and($email->getTextBody())->toStartWith("Hi Jane,\n\nQuick thought.\nArif")
        ->and($email->getHeaders()->has('In-Reply-To'))->toBeFalse()
        ->and($thread->campaign_lead_id)->toBeNull()
        ->and($thread->email_account_id)->toBe($mailbox->id)
        ->and($thread->unread)->toBeFalse()
        ->and(InboxMessage::sole()->status)->toBe(InboxMessageStatus::Sent)
        ->and($lead->refresh()->status)->toBe(LeadStatus::Contacted)
        ->and($lead->activities()->where('type', LeadActivityType::EmailSent->value)->exists())->toBeTrue();
});

it('is also available from the leads table', function () {
    $transport = fakeTransport();
    $workspace = actingInWorkspace();
    ['mailbox' => $mailbox, 'lead' => $lead] = directEmailSetup($workspace);

    Livewire::test(ListLeads::class)
        ->callAction(TestAction::make('sendEmail')->table($lead), data: [
            'email_account_id' => $mailbox->id,
            'subject' => 'Hello',
            'body' => 'Hi Jane',
        ])
        ->assertNotified('Email queued');

    expect($transport->sent)->toHaveCount(1);
});

it('puts the lead\'s reply in the same Unibox conversation', function () {
    $transport = fakeTransport();
    $workspace = actingInWorkspace();
    ['mailbox' => $mailbox, 'lead' => $lead] = directEmailSetup($workspace);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('sendEmail', data: ['email_account_id' => $mailbox->id, 'subject' => 'Hello', 'body' => 'Hi Jane']);

    $sent = InboxMessage::sole();
    $reply = app(InboundMailProcessor::class)->process($mailbox, rawEmail([
        'From' => 'Jane <jane@acme.test>',
        'Subject' => 'Re: Hello',
        'In-Reply-To' => "<{$sent->message_id}>",
    ]));

    $thread = InboxThread::sole();

    expect($reply->inbox_thread_id)->toBe($thread->id)
        ->and($thread->unread)->toBeTrue()
        ->and($thread->message_count)->toBe(2)
        ->and($lead->refresh()->status)->toBe(LeadStatus::Replied);

    // Answering it from the Unibox stays in the thread.
    Livewire::test(ViewInboxThread::class, ['record' => $thread->getRouteKey()])
        ->assertSee('Sounds interesting')
        ->callAction('reply', data: ['body' => 'Great, Tuesday?']);

    expect($transport->sent[1]->getHeaders()->get('In-Reply-To')->getBodyAsString())->toBe("<{$reply->message_id}>")
        ->and($transport->sent[1]->getSubject())->toBe('Re: Hello');
});

it('matches a reply without thread headers by the sender', function () {
    fakeTransport();
    $workspace = actingInWorkspace();
    ['mailbox' => $mailbox, 'lead' => $lead] = directEmailSetup($workspace);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('sendEmail', data: ['email_account_id' => $mailbox->id, 'subject' => 'Hello', 'body' => 'Hi Jane']);

    $reply = app(InboundMailProcessor::class)->process($mailbox, rawEmail(['From' => 'jane@acme.test']));

    expect($reply?->inbox_thread_id)->toBe(InboxThread::sole()->id);
});

it('never emails a suppressed lead', function () {
    $transport = fakeTransport();
    $workspace = actingInWorkspace();
    ['mailbox' => $mailbox, 'lead' => $lead] = directEmailSetup($workspace);
    app(SuppressionList::class)->add($workspace->id, 'jane@acme.test');

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('sendEmail', data: ['email_account_id' => $mailbox->id, 'subject' => 'Hello', 'body' => 'Hi Jane'])
        ->assertNotified('Email not sent');

    expect($transport->sent)->toBe([])
        ->and(InboxThread::count())->toBe(0);
});

it('only sends from this workspace\'s active mailboxes', function () {
    $transport = fakeTransport();
    $otherMailbox = EmailAccount::factory()->create();
    $workspace = actingInWorkspace();
    ['lead' => $lead] = directEmailSetup($workspace);
    $paused = EmailAccount::factory()->for($workspace)->paused()->create();

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('sendEmail', data: ['email_account_id' => $otherMailbox->id, 'subject' => 'Hello', 'body' => 'Hi'])
        ->assertHasFormErrors(['email_account_id'])
        ->callAction('sendEmail', data: ['email_account_id' => $paused->id, 'subject' => 'Hello', 'body' => 'Hi'])
        ->assertHasFormErrors(['email_account_id']);

    expect($transport->sent)->toBe([]);
});

it('hides the action from clients', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    ['lead' => $lead] = directEmailSetup($workspace);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->assertActionHidden('sendEmail');
});
