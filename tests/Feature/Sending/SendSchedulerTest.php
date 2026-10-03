<?php

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignStatus;
use App\Enums\EmailAccountStatus;
use App\Enums\EmailMessageStatus;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Leads\SuppressionList;
use App\Services\Sending\SendScheduler;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    // Wednesday 10:00 UTC.
    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(10, 0));
    $this->scheduler = app(SendScheduler::class);
});

function sentMessage(EmailAccount $mailbox, CampaignLead $campaignLead, int $position = 1): EmailMessage
{
    $message = new EmailMessage;
    $message->forceFill([
        'workspace_id' => $mailbox->workspace_id,
        'campaign_id' => $campaignLead->campaign_id,
        'campaign_lead_id' => $campaignLead->id,
        'lead_id' => $campaignLead->lead_id,
        'email_account_id' => $mailbox->id,
        'step_position' => $position,
        'token' => Str::random(40),
        'status' => EmailMessageStatus::Sent,
        'sent_at' => now(),
    ])->save();

    return $message;
}

it('queues one email per mailbox and claims the lead', function () {
    ['mailbox' => $mailbox] = readyCampaign(leads: 3);

    expect($this->scheduler->tick())->toBe(1);

    Queue::assertPushedOn('sending', SendCampaignEmail::class, fn ($job) => $job->emailAccountId === $mailbox->id && $job->stepPosition === 1);

    $claimed = CampaignLead::whereNotNull('email_account_id')->sole();

    expect($claimed->email_account_id)->toBe($mailbox->id)
        ->and($claimed->next_send_at->greaterThan(now()->addMinutes(29)))->toBeTrue()
        ->and($mailbox->refresh()->next_send_at->equalTo(now()->addSeconds(60)))->toBeTrue();
});

it('waits for the mailbox gap between emails', function () {
    readyCampaign(leads: 3);

    expect($this->scheduler->tick())->toBe(1)
        ->and($this->scheduler->tick())->toBe(0);

    $this->travel(61)->seconds();

    expect($this->scheduler->tick())->toBe(1);
});

it('rotates across the campaign\'s mailboxes', function () {
    ['campaign' => $campaign, 'workspace' => $workspace] = readyCampaign(leads: 2);
    $second = EmailAccount::factory()->for($workspace)->create([
        'send_window_start' => '00:00', 'send_window_end' => '23:59', 'send_days' => [1, 2, 3, 4, 5, 6, 7],
    ]);
    $campaign->emailAccounts()->attach($second);

    expect($this->scheduler->tick())->toBe(2)
        ->and(CampaignLead::pluck('email_account_id')->sort()->values()->all())
        ->toBe(EmailAccount::pluck('id')->sort()->values()->all());
});

it('keeps each lead on its first mailbox', function () {
    ['campaign' => $campaign, 'workspace' => $workspace, 'mailbox' => $first] = readyCampaign(leads: 1);
    $second = EmailAccount::factory()->for($workspace)->create([
        'send_window_start' => '00:00', 'send_window_end' => '23:59', 'send_days' => [1, 2, 3, 4, 5, 6, 7],
    ]);
    $campaign->emailAccounts()->attach($second);
    CampaignLead::query()->update(['email_account_id' => $first->id]);
    $first->forceFill(['next_send_at' => now()->addHour()])->save();

    // The lead's own mailbox is busy, so nobody sends to it.
    expect($this->scheduler->tick())->toBe(0);

    // Once its mailbox stops being active, another one takes over.
    $first->forceFill(['status' => EmailAccountStatus::Paused])->save();
    expect($this->scheduler->tick())->toBe(1);
    Queue::assertPushed(SendCampaignEmail::class, fn ($job) => $job->emailAccountId === $second->id);
});

it('respects campaign and mailbox send windows and days', function (array $campaign, array $mailbox) {
    readyCampaign(leads: 1, campaign: $campaign, mailbox: $mailbox);

    expect($this->scheduler->tick())->toBe(0);
})->with([
    'campaign hours' => [['send_window_start' => '11:00', 'send_window_end' => '17:00'], []],
    'campaign days' => [['send_days' => [1, 2]], []],
    'mailbox hours' => [[], ['send_window_start' => '13:00', 'send_window_end' => '14:00']],
    'mailbox days' => [[], ['send_days' => [6, 7]]],
    'campaign time zone' => [['timezone' => 'Asia/Tokyo', 'send_window_start' => '09:00', 'send_window_end' => '17:00'], []],
]);

it('respects the mailbox daily limit', function () {
    ['mailbox' => $mailbox] = readyCampaign(leads: 2, mailbox: ['daily_limit' => 1]);
    sentMessage($mailbox, CampaignLead::first());

    expect($this->scheduler->tick())->toBe(0);
});

it('respects the campaign daily limit', function () {
    ['mailbox' => $mailbox] = readyCampaign(leads: 2, campaign: ['daily_limit' => 1]);
    sentMessage($mailbox, CampaignLead::first());

    expect($this->scheduler->tick())->toBe(0);
});

it('stops leads suppressed after enrollment', function () {
    ['workspace' => $workspace, 'leads' => $leads] = readyCampaign(leads: 1);
    app(SuppressionList::class)->add($workspace->id, $leads[0]->email);

    expect($this->scheduler->tick())->toBe(0)
        ->and(CampaignLead::sole()->status)->toBe(CampaignLeadStatus::Stopped);
});

it('ignores paused campaigns and completes finished ones', function () {
    ['campaign' => $campaign] = readyCampaign(leads: 1);

    app(CampaignLauncher::class)->pause($campaign);
    expect($this->scheduler->tick())->toBe(0);

    app(CampaignLauncher::class)->resume($campaign);
    CampaignLead::query()->update(['status' => CampaignLeadStatus::Completed->value]);

    expect($this->scheduler->tick())->toBe(0)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Completed);
});

it('runs from the scheduler command', function () {
    readyCampaign(leads: 1);

    $this->artisan('campaigns:send')->assertSuccessful();

    Queue::assertPushed(SendCampaignEmail::class, 1);
});
