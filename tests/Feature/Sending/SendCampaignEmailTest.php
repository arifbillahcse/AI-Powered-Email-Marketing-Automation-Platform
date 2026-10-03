<?php

use App\Enums\CampaignLeadStatus;
use App\Enums\EmailAccountStatus;
use App\Enums\EmailMessageStatus;
use App\Enums\LeadStatus;
use App\Enums\SuppressionReason;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\Suppression;
use App\Services\Leads\SuppressionList;
use App\Services\Sending\TrackingUrls;

function sendNext(CampaignLead $campaignLead, int $mailboxId): void
{
    $campaignLead->refresh();
    SendCampaignEmail::dispatchSync($campaignLead->id, $mailboxId, $campaignLead->steps_sent + 1);
}

it('sends the first email with compliance headers and moves the lead on', function () {
    $transport = fakeTransport();
    ['mailbox' => $mailbox, 'leads' => $leads] = readyCampaign();
    $lead = $leads[0];
    $lead->update(['first_name' => 'Jane']);

    sendNext(CampaignLead::sole(), $mailbox->id);

    $email = $transport->sent[0];
    $message = EmailMessage::sole();
    $headers = $email->getHeaders();
    $campaignLead = CampaignLead::sole();

    expect($email->getSubject())->toBe('Quick question, Jane')
        ->and($email->getFrom()[0]->getAddress())->toBe('arif@softorio.com')
        ->and($email->getTo()[0]->getAddress())->toBe($lead->email)
        ->and($headers->get('Message-ID')->getBodyAsString())->toBe("<{$message->message_id}>")
        ->and($headers->get('List-Unsubscribe')->getBodyAsString())->toBe('<'.url("/u/{$message->token}").'>')
        ->and($headers->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
        ->and($email->getHtmlBody())->toContain(url("/u/{$message->token}"))
        ->and($message->status)->toBe(EmailMessageStatus::Sent)
        ->and($campaignLead->steps_sent)->toBe(1)
        ->and($campaignLead->status)->toBe(CampaignLeadStatus::Active)
        ->and($campaignLead->next_send_at->toDateString())->toBe(now()->addDays(3)->toDateString())
        ->and($lead->refresh()->status)->toBe(LeadStatus::Contacted)
        ->and($lead->last_contacted_at)->not->toBeNull()
        ->and(EmailEvent::where('type', 'sent')->count())->toBe(1);
});

it('threads the follow-up and completes the sequence', function () {
    $transport = fakeTransport();
    ['mailbox' => $mailbox] = readyCampaign();
    $campaignLead = CampaignLead::sole();

    sendNext($campaignLead, $mailbox->id);
    sendNext($campaignLead, $mailbox->id);

    $first = EmailMessage::where('step_position', 1)->sole();
    $followUp = $transport->sent[1];

    expect($followUp->getSubject())->toStartWith('Re: Quick question')
        ->and($followUp->getHeaders()->get('In-Reply-To')->getBodyAsString())->toBe("<{$first->message_id}>")
        ->and($campaignLead->refresh()->status)->toBe(CampaignLeadStatus::Completed)
        ->and($campaignLead->next_send_at)->toBeNull();
});

it('never sends the same step twice', function () {
    $transport = fakeTransport();
    ['mailbox' => $mailbox] = readyCampaign();
    $campaignLead = CampaignLead::sole();

    SendCampaignEmail::dispatchSync($campaignLead->id, $mailbox->id, 1);
    SendCampaignEmail::dispatchSync($campaignLead->id, $mailbox->id, 1);

    expect($transport->sent)->toHaveCount(1)
        ->and($campaignLead->refresh()->steps_sent)->toBe(1);
});

it('adds open and click tracking when enabled', function () {
    $transport = fakeTransport();
    ['mailbox' => $mailbox, 'campaign' => $campaign] = readyCampaign(campaign: ['track_opens' => true, 'track_clicks' => true]);
    $campaign->steps()->where('position', 1)->update(['body' => '<p>See <a href="https://softorio.com/pricing?a=1&amp;b=2">pricing</a></p>']);

    sendNext(CampaignLead::sole(), $mailbox->id);

    $message = EmailMessage::sole();
    $html = $transport->sent[0]->getHtmlBody();
    $urls = app(TrackingUrls::class);

    expect($html)->toContain(e($urls->open($mailbox, $message->token)))
        ->toContain(e($urls->click($mailbox, $message->token, 'https://softorio.com/pricing?a=1&b=2')))
        ->toContain('href="'.url("/u/{$message->token}").'"')
        ->not->toContain('href="https://softorio.com/pricing');
});

it('checks the suppression list again right before sending', function () {
    $transport = fakeTransport();
    ['mailbox' => $mailbox, 'workspace' => $workspace, 'leads' => $leads] = readyCampaign();
    app(SuppressionList::class)->add($workspace->id, $leads[0]->email);

    sendNext(CampaignLead::sole(), $mailbox->id);

    expect($transport->sent)->toBe([])
        ->and(CampaignLead::sole()->status)->toBe(CampaignLeadStatus::Stopped);
});

it('suppresses hard bounces', function () {
    fakeTransport('Expected response code "250" but got code "550", with message "550 5.1.1 The email account that you tried to reach does not exist."', 550);
    ['mailbox' => $mailbox, 'leads' => $leads] = readyCampaign();

    sendNext(CampaignLead::sole(), $mailbox->id);

    expect(EmailMessage::sole()->status)->toBe(EmailMessageStatus::Bounced)
        ->and(CampaignLead::sole()->status)->toBe(CampaignLeadStatus::Bounced)
        ->and($leads[0]->refresh()->status)->toBe(LeadStatus::Bounced)
        ->and(Suppression::sole()->reason)->toBe(SuppressionReason::Bounced)
        ->and($mailbox->refresh()->status)->toBe(EmailAccountStatus::Active);
});

it('flags the mailbox when its login is refused and retries the lead later', function () {
    fakeTransport('Expected response code "235" but got code "535", with message "535 5.7.8 Username and Password not accepted."', 535);
    ['mailbox' => $mailbox] = readyCampaign();

    sendNext(CampaignLead::sole(), $mailbox->id);

    $campaignLead = CampaignLead::sole();

    expect($mailbox->refresh()->status)->toBe(EmailAccountStatus::Error)
        ->and(EmailMessage::count())->toBe(0)
        ->and($campaignLead->status)->toBe(CampaignLeadStatus::Active)
        ->and($campaignLead->steps_sent)->toBe(0)
        ->and($campaignLead->next_send_at->greaterThan(now()->addMinutes(14)))->toBeTrue();
});

it('retries temporary failures without touching the mailbox', function () {
    fakeTransport('Expected response code "250" but got code "421", with message "421 4.7.0 Try again later."', 421);
    ['mailbox' => $mailbox] = readyCampaign();

    sendNext(CampaignLead::sole(), $mailbox->id);

    expect($mailbox->refresh()->status)->toBe(EmailAccountStatus::Active)
        ->and(EmailMessage::count())->toBe(0)
        ->and(CampaignLead::sole()->steps_sent)->toBe(0);
});
