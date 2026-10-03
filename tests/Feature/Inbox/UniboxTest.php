<?php

use App\Enums\InboxMessageStatus;
use App\Enums\LeadStatus;
use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\InboxThreads\Pages\ListInboxThreads;
use App\Filament\App\Resources\InboxThreads\Pages\ViewInboxThread;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Lead;
use App\Models\Workspace;
use App\Services\Leads\SuppressionList;
use Livewire\Livewire;

/**
 * A conversation with one inbound reply, created directly.
 */
function uniboxThread(Workspace $workspace, array $thread = [], string $body = 'Yes, let\'s talk.'): InboxThread
{
    $mailbox = EmailAccount::factory()->for($workspace)->create(['email' => 'arif@softorio.com', 'from_name' => 'Arif']);
    $lead = Lead::factory()->for($workspace)->create(['status' => LeadStatus::Contacted]);
    $campaign = Campaign::factory()->for($workspace)->withSteps()->create();

    $campaignLead = new CampaignLead;
    $campaignLead->forceFill(['campaign_id' => $campaign->id, 'lead_id' => $lead->id, 'email_account_id' => $mailbox->id, 'steps_sent' => 1])->save();

    $model = new InboxThread;
    $model->forceFill(array_merge([
        'workspace_id' => $workspace->id,
        'campaign_lead_id' => $campaignLead->id,
        'campaign_id' => $campaign->id,
        'lead_id' => $lead->id,
        'email_account_id' => $mailbox->id,
        'subject' => 'Quick question',
        'snippet' => $body,
        'message_count' => 1,
        'last_message_at' => now(),
        'last_inbound_at' => now(),
    ], $thread))->save();

    $message = new InboxMessage;
    $message->forceFill([
        'workspace_id' => $workspace->id,
        'inbox_thread_id' => $model->id,
        'email_account_id' => $mailbox->id,
        'direction' => InboxMessage::INBOUND,
        'message_id' => "reply-{$model->id}@acme.test",
        'references' => "sent-{$model->id}@softorio.com",
        'from_email' => $lead->email,
        'to_email' => $mailbox->email,
        'subject' => 'Re: Quick question',
        'body' => $body,
        'auto_reply' => $model->auto_reply,
        'sent_at' => now(),
    ])->save();

    return $model;
}

it('lists this workspace\'s replies and hides out-of-office by default', function () {
    // Before signing in: Filament attaches records created afterwards to the current workspace.
    $theirs = uniboxThread(Workspace::factory()->create());
    $workspace = actingInWorkspace();
    $reply = uniboxThread($workspace);
    $outOfOffice = uniboxThread($workspace, ['auto_reply' => true]);

    Livewire::test(ListInboxThreads::class)
        ->assertCanSeeTableRecords([$reply])
        ->assertCanNotSeeTableRecords([$outOfOffice, $theirs])
        ->filterTable('auto_reply', true)
        ->assertCanSeeTableRecords([$outOfOffice])
        ->assertCanNotSeeTableRecords([$reply]);
});

it('shows the conversation as plain text and marks it read', function () {
    $workspace = actingInWorkspace();
    $thread = uniboxThread($workspace, body: "Sure!\n<script>alert('x')</script>");

    Livewire::test(ViewInboxThread::class, ['record' => $thread->getRouteKey()])
        ->assertSee('Sure!')
        ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', escape: false)
        ->assertDontSee("<script>alert('x')</script>", escape: false);

    expect($thread->refresh()->unread)->toBeFalse();
});

it('replies in-thread from the original mailbox', function () {
    $transport = fakeTransport();
    $workspace = actingInWorkspace();
    $thread = uniboxThread($workspace);

    Livewire::test(ViewInboxThread::class, ['record' => $thread->getRouteKey()])
        ->callAction('reply', data: ['body' => "Great, how about Tuesday?\nArif"])
        ->assertHasNoFormErrors()
        ->assertNotified('Reply queued');

    $email = $transport->sent[0];
    $reply = InboxMessage::query()->where('direction', InboxMessage::OUTBOUND)->sole();
    $headers = $email->getHeaders();

    expect($email->getFrom()[0]->getAddress())->toBe('arif@softorio.com')
        ->and($email->getTo()[0]->getAddress())->toBe($thread->lead()->value('email'))
        ->and($email->getSubject())->toBe('Re: Quick question')
        ->and($email->getTextBody())->toStartWith("Great, how about Tuesday?\nArif")
        ->and($headers->get('In-Reply-To')->getBodyAsString())->toBe("<reply-{$thread->id}@acme.test>")
        ->and($headers->get('References')->getBodyAsString())->toBe("<sent-{$thread->id}@softorio.com> <reply-{$thread->id}@acme.test>")
        ->and($reply->status)->toBe(InboxMessageStatus::Sent)
        ->and($reply->message_id)->toEndWith('@softorio.com')
        ->and($thread->refresh()->message_count)->toBe(2);
});

it('never replies to a suppressed address', function () {
    $transport = fakeTransport();
    $workspace = actingInWorkspace();
    $thread = uniboxThread($workspace);
    app(SuppressionList::class)->add($workspace->id, Lead::find($thread->lead_id)->email);

    Livewire::test(ViewInboxThread::class, ['record' => $thread->getRouteKey()])
        ->callAction('reply', data: ['body' => 'Hello again']);

    expect($transport->sent)->toBe([])
        ->and(InboxMessage::query()->where('direction', InboxMessage::OUTBOUND)->sole()->status)->toBe(InboxMessageStatus::Failed)
        ->and(auth()->user()->notifications()->count())->toBe(1);
});

it('labels the lead from the conversation', function () {
    $workspace = actingInWorkspace();
    $thread = uniboxThread($workspace);

    Livewire::test(ViewInboxThread::class, ['record' => $thread->getRouteKey()])
        ->callAction('label', data: ['status' => LeadStatus::MeetingBooked->value])
        ->assertHasNoFormErrors();

    expect(Lead::find($thread->lead_id)->status)->toBe(LeadStatus::MeetingBooked);

    Livewire::test(ListInboxThreads::class)
        ->filterTable('label', LeadStatus::MeetingBooked->value)
        ->assertCanSeeTableRecords([$thread]);
});

it('lets clients read conversations but not reply', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    $thread = uniboxThread($workspace);

    Livewire::test(ViewInboxThread::class, ['record' => $thread->getRouteKey()])
        ->assertSee('Yes, let')
        ->assertActionHidden('reply')
        ->assertActionHidden('label');

    expect($thread->refresh()->unread)->toBeTrue();
});
