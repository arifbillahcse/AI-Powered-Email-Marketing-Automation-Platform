<?php

use App\Enums\EmailAccountStatus;
use App\Filament\App\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\App\Resources\Campaigns\RelationManagers\CampaignLeadsRelationManager;
use App\Jobs\SendCampaignEmail;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Services\Sending\SendingDiagnostics;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @return list<string>
 */
function diagnose(Campaign $campaign): array
{
    return array_column(app(SendingDiagnostics::class)->check($campaign->refresh())['checks'], 'message');
}

it('says a ready campaign is sending', function () {
    ['campaign' => $campaign, 'mailbox' => $mailbox] = readyCampaign(2);

    $result = app(SendingDiagnostics::class)->check($campaign);

    expect($result['sending'])->toBeTrue()
        ->and(array_column($result['checks'], 'message'))->toContain(
            '2 leads waiting for an email now.',
            "{$mailbox->email} is ready to send.",
        );
});

it('explains a closed campaign schedule and when it opens', function () {
    $this->travelTo(now()->startOfWeek()->setTime(12, 0)); // Monday noon UTC
    ['campaign' => $campaign] = readyCampaign(1, ['send_window_start' => '09:00', 'send_window_end' => '10:00']);

    expect(app(SendingDiagnostics::class)->headline($campaign))
        ->toBe("Outside the campaign's schedule (every day, 09:00–10:00 UTC). It opens again 21 hours from now (Tue 09:00).");
});

it('points at the mailbox when its own window is closed', function () {
    $this->travelTo(now()->startOfWeek()->addDays(5)->setTime(12, 0)); // Saturday
    ['campaign' => $campaign, 'mailbox' => $mailbox] = readyCampaign(1, mailbox: ['send_days' => [1, 2, 3, 4, 5], 'send_window_start' => '09:00', 'send_window_end' => '17:00']);

    expect(diagnose($campaign))->toContain(
        "{$mailbox->email}: outside its own sending window (Mon–Fri, 09:00–17:00 UTC). Widen it in the mailbox's Sending limits, or leave it open all day.",
    )->and(app(SendingDiagnostics::class)->check($campaign)['sending'])->toBeFalse();
});

it('reports paused mailboxes, daily limits and follow-up delays', function () {
    ['campaign' => $campaign, 'mailbox' => $mailbox] = readyCampaign(1, ['daily_limit' => 1]);

    $mailbox->forceFill(['status' => EmailAccountStatus::Paused])->save();
    expect(diagnose($campaign))->toContain("{$mailbox->email}: paused. Resume it on the Email accounts page.");

    $mailbox->forceFill(['status' => EmailAccountStatus::Active])->save();
    fakeTransport();
    SendCampaignEmail::dispatchSync(CampaignLead::sole()->id, $mailbox->id, 1);

    expect(app(SendingDiagnostics::class)->headline($campaign))->toBe("The campaign's daily limit is reached (1 / 1 today).")
        ->and(diagnose($campaign))->toContain('No email is due right now. The next one is due 3 days from now (follow-up delays).');
});

it('notices when the background queue is not being worked', function () {
    ['campaign' => $campaign] = readyCampaign(1);
    config(['queue.default' => 'database']);

    DB::table('jobs')->insert([
        'queue' => 'sending',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => now()->subMinutes(10)->getTimestamp(),
        'created_at' => now()->subMinutes(10)->getTimestamp(),
    ]);

    expect(app(SendingDiagnostics::class)->headline($campaign))->toContain("The background worker isn't running");
});

it('explains drafts', function () {
    ['campaign' => $campaign] = readyCampaign(1, launch: false);

    expect(app(SendingDiagnostics::class)->headline($campaign))->toBe('The campaign is a draft. Launch it to start sending.');
});

it('prints why nothing was queued', function () {
    $this->travelTo(now()->startOfWeek()->setTime(12, 0));
    ['campaign' => $campaign] = readyCampaign(1, ['name' => 'October founders', 'send_window_start' => '09:00', 'send_window_end' => '10:00']);

    $this->artisan('campaigns:send')
        ->expectsOutputToContain('Queued 0 emails.')
        ->expectsOutputToContain('October founders')
        ->assertSuccessful();
});

it('shows the sending status on the campaign page', function () {
    ['workspace' => $workspace, 'campaign' => $campaign] = readyCampaign(1);
    actingInWorkspace(workspace: $workspace);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->mountAction('sendingStatus')
        ->assertMountedActionModalSee('1 lead waiting for an email now.');
});

it('warns at the top of the page when an active campaign cannot send', function () {
    $this->travelTo(now()->startOfWeek()->setTime(12, 0));
    ['workspace' => $workspace, 'campaign' => $campaign] = readyCampaign(1, ['send_window_start' => '09:00', 'send_window_end' => '10:00']);
    actingInWorkspace(workspace: $workspace);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertSee('Not sending right now: Outside the campaign&#039;s schedule', escape: false);
});

it('shows each lead\'s progress through the sequence', function () {
    ['workspace' => $workspace, 'campaign' => $campaign] = readyCampaign(1);
    actingInWorkspace(workspace: $workspace);

    Livewire::test(CampaignLeadsRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->assertSee('0 of 2')
        ->assertSee('Next: email 1 of 2');
});
