<?php

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignStatus;
use App\Enums\EmailAccountStatus;
use App\Enums\LeadStatus;
use App\Enums\SuppressionReason;
use App\Enums\WorkspaceRole;
use App\Filament\App\Tenancy\WorkspaceSettings;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\EmailMessage;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Compliance\DeliverabilityGuard;
use App\Services\Inbox\InboundMailProcessor;
use App\Services\Sending\EngagementRecorder;
use App\Services\Sending\SendingDiagnostics;
use Livewire\Livewire;

/**
 * A campaign that sent its first email to $leads leads, with an owner to notify.
 *
 * @return array<string, mixed>
 */
function sentForSafety(int $leads): array
{
    $transport = fakeTransport();
    $setup = readyCampaign($leads, mailbox: ['daily_limit' => 200]);
    $owner = User::factory()->create();
    $setup['workspace']->addMember($owner, WorkspaceRole::Owner);

    CampaignLead::query()->each(fn (CampaignLead $lead) => SendCampaignEmail::dispatchSync($lead->id, $setup['mailbox']->id, 1));

    return [...$setup, 'owner' => $owner, 'transport' => $transport, 'messages' => EmailMessage::query()->orderBy('id')->get()];
}

/**
 * A feedback-loop (ARF) spam report about one of our emails.
 */
function feedbackReport(EmailMessage $message, string $recipient): string
{
    return implode("\r\n", [
        'From: Yahoo Feedback <feedback@yahoo.test>',
        'To: arif@softorio.com',
        'Subject: FW: Quick question',
        'Date: Fri, 02 Oct 2026 10:00:00 +0000',
        'Message-ID: <report-'.$message->id.'@yahoo.test>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/report; report-type=feedback-report; boundary="b1"',
        '',
        '--b1',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'This is an email abuse report.',
        '',
        '--b1',
        'Content-Type: message/feedback-report',
        '',
        'Feedback-Type: abuse',
        'User-Agent: Yahoo!-Mail-Feedback/2.0',
        'Version: 1',
        "Original-Rcpt-To: {$recipient}",
        '',
        '--b1',
        'Content-Type: text/rfc822-headers',
        '',
        'From: Arif <arif@softorio.com>',
        "To: {$recipient}",
        "Message-ID: <{$message->message_id}>",
        'Subject: Quick question',
        '',
        '--b1--',
        '',
    ]);
}

it('pauses the mailbox and campaign when the bounce rate is too high', function () {
    ['campaign' => $campaign, 'mailbox' => $mailbox, 'owner' => $owner, 'messages' => $messages] = sentForSafety(20);
    $engagement = app(EngagementRecorder::class);

    $engagement->hardBounce($messages[0]->refresh(), '550 User unknown');
    expect($mailbox->refresh()->status)->toBe(EmailAccountStatus::Active); // 5%: at the limit, not over

    $engagement->hardBounce($messages[1]->refresh(), '550 User unknown');

    expect($mailbox->refresh()->status)->toBe(EmailAccountStatus::Paused)
        ->and($mailbox->paused_reason)->toBe('Auto-paused: bounce rate 10% (2 of 20 emails in the last 7 days) is over the 5% limit.')
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->paused_reason)->toStartWith('Auto-paused: bounce rate 10%')
        ->and($owner->notifications()->count())->toBe(2)
        ->and(app(SendingDiagnostics::class)->headline($campaign))->toStartWith('Auto-paused: bounce rate 10%');
});

it('waits for enough emails before judging a rate', function () {
    ['campaign' => $campaign, 'mailbox' => $mailbox, 'messages' => $messages] = sentForSafety(5);

    app(EngagementRecorder::class)->hardBounce($messages[0]->refresh(), '550 User unknown');

    expect($mailbox->refresh()->status)->toBe(EmailAccountStatus::Active)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Active);
});

it('starts counting again after a resume', function () {
    ['campaign' => $campaign, 'mailbox' => $mailbox, 'messages' => $messages] = sentForSafety(20);
    $engagement = app(EngagementRecorder::class);
    $engagement->hardBounce($messages[0]->refresh(), '550');
    $engagement->hardBounce($messages[1]->refresh(), '550');

    $this->travel(1)->minutes();
    app(CampaignLauncher::class)->resume($campaign->refresh());
    $mailbox->refresh()->forceFill(['status' => EmailAccountStatus::Active, 'paused_reason' => null, 'safety_reset_at' => now()])->save();
    $engagement->hardBounce($messages[2]->refresh(), '550');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Active)
        ->and($campaign->paused_reason)->toBeNull()
        ->and($campaign->safety_reset_at)->not->toBeNull()
        ->and($mailbox->refresh()->status)->toBe(EmailAccountStatus::Active);
});

it('records spam complaints from feedback-loop reports', function () {
    ['mailbox' => $mailbox, 'messages' => $messages] = sentForSafety(3);
    $message = $messages[0];
    $lead = $message->lead()->first();

    app(InboundMailProcessor::class)->process($mailbox, feedbackReport($message, $lead->email));
    app(InboundMailProcessor::class)->process($mailbox, feedbackReport($message, $lead->email)); // twice: counted once

    expect($message->refresh()->complained_at)->not->toBeNull()
        ->and(Suppression::query()->where('value', $lead->email)->sole()->reason)->toBe(SuppressionReason::Complaint)
        ->and($lead->refresh()->status)->toBe(LeadStatus::Unsubscribed)
        ->and(CampaignLead::query()->where('lead_id', $lead->id)->sole()->status)->toBe(CampaignLeadStatus::Unsubscribed)
        ->and($message->events()->where('type', 'complaint')->count())->toBe(1);
});

it('finds the complaint by recipient when the report has no message ID', function () {
    ['mailbox' => $mailbox, 'messages' => $messages] = sentForSafety(2);
    $message = $messages[1];
    $email = $message->lead()->value('email');
    $message->forceFill(['message_id' => 'gone@softorio.com'])->save();

    app(InboundMailProcessor::class)->process($mailbox, str_replace('<gone@softorio.com>', '', feedbackReport($message, $email)));

    expect($message->refresh()->complained_at)->not->toBeNull();
});

it('pauses on a spam complaint rate over 0.3%', function () {
    ['campaign' => $campaign, 'mailbox' => $mailbox, 'messages' => $messages] = sentForSafety(20);

    app(EngagementRecorder::class)->complaint($messages[0]->refresh());

    expect($mailbox->refresh()->paused_reason)->toBe('Auto-paused: spam complaint rate 5% (1 of 20 emails in the last 7 days) is over the 0.3% limit.')
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Paused);
});

it('can be turned off', function () {
    config(['outreach.safety.enabled' => false]);
    ['mailbox' => $mailbox, 'messages' => $messages] = sentForSafety(20);

    app(EngagementRecorder::class)->complaint($messages[0]->refresh());

    expect($mailbox->refresh()->status)->toBe(EmailAccountStatus::Active);
});

it('reports rates for a mailbox', function () {
    ['mailbox' => $mailbox, 'messages' => $messages] = sentForSafety(4);
    app(EngagementRecorder::class)->hardBounce($messages[0]->refresh(), '550');

    expect(app(DeliverabilityGuard::class)->rates(EmailMessage::query()->where('email_account_id', $mailbox->id)))
        ->toBe(['sent' => 4, 'bounced' => 1, 'complained' => 0, 'bounce_rate' => 25.0, 'complaint_rate' => 0.0]);
});

it('never sends a campaign email without the mailing address', function () {
    $transport = fakeTransport();
    ['workspace' => $workspace, 'campaign' => $campaign, 'mailbox' => $mailbox] = readyCampaign(1);
    $workspace->forceFill(['address_line1' => null])->save();

    SendCampaignEmail::dispatchSync(CampaignLead::sole()->id, $mailbox->id, 1);

    expect($transport->sent)->toBe([])
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->paused_reason)->toContain('mailing address is missing');
});

it('keeps the mailing address required while campaigns run', function () {
    ['workspace' => $workspace] = readyCampaign(1);
    actingInWorkspace(workspace: $workspace);

    Livewire::test(WorkspaceSettings::class)
        ->fillForm(['address_line1' => '', 'city' => ''])
        ->call('save')
        ->assertHasFormErrors(['address_line1' => 'required', 'city' => 'required']);
});
