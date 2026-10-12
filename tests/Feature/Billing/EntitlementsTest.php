<?php

use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\TeamActionException;
use App\Filament\App\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\App\Resources\EmailAccounts\Pages\CreateEmailAccount;
use App\Filament\App\Resources\Leads\Pages\CreateLead;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Services\Billing\Entitlements;
use App\Services\Sending\SendingDiagnostics;
use App\Services\Sending\SendScheduler;
use App\Services\Workspaces\TeamManager;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

it('is unlimited with billing off', function () {
    ['workspace' => $workspace] = readyCampaign(1);

    expect(app(Entitlements::class)->limit($workspace, 'leads'))->toBeNull()
        ->and(app(Entitlements::class)->denies($workspace, 'mailboxes', 1000))->toBeNull()
        ->and(app(Entitlements::class)->sendingBlockedReason($workspace))->toBeNull()
        ->and(app(Entitlements::class)->allowsModule($workspace, 'ai'))->toBeTrue();
});

it('blocks a new mailbox over the plan limit', function () {
    $plan = billingOn(['limits' => ['mailboxes' => 1]]);
    $workspace = actingInWorkspace();
    subscribe($workspace, $plan);
    EmailAccount::factory()->for($workspace)->create();

    Livewire::test(CreateEmailAccount::class)
        ->fillForm(mailboxFormData(['email' => 'second@softorio.com']))
        ->call('create')
        ->assertNotified('Mailbox limit reached');

    expect(EmailAccount::count())->toBe(1);
});

it('blocks new leads over the plan limit', function () {
    $plan = billingOn(['limits' => ['leads' => 1]]);
    $workspace = actingInWorkspace();
    subscribe($workspace, $plan);
    Lead::factory()->for($workspace)->create();

    Livewire::test(CreateLead::class)
        ->fillForm(['email' => 'new@acme.test', 'status' => 'new'])
        ->call('create')
        ->assertNotified('Lead limit reached');

    expect(Lead::count())->toBe(1);
});

it('blocks invitations over the team limit', function () {
    $plan = billingOn(['limits' => ['team_members' => 2]]);
    $workspace = actingInWorkspace();
    subscribe($workspace, $plan);
    $team = app(TeamManager::class);

    $team->invite($workspace, auth()->user(), 'one@acme.test', WorkspaceRole::Member);
    $team->invite($workspace, auth()->user(), 'one@acme.test', WorkspaceRole::Admin); // re-invite is fine

    expect(fn () => $team->invite($workspace, auth()->user(), 'two@acme.test', WorkspaceRole::Member))
        ->toThrow(TeamActionException::class, 'Your plan includes 2 team members. Upgrade on the Billing page to add more.');
});

it('stops sending when the subscription has expired', function () {
    $plan = billingOn();
    ['workspace' => $workspace, 'campaign' => $campaign] = readyCampaign(2);
    subscribe($workspace, $plan)->forceFill(['status' => SubscriptionStatus::Expired])->save();
    Bus::fake();

    expect(app(SendScheduler::class)->tick())->toBe(0)
        ->and(app(SendingDiagnostics::class)->headline($campaign))->toBe('Your subscription has expired. Pay the open invoice on the Billing page to keep sending.');
});

it('shares the monthly email limit across campaigns', function () {
    $plan = billingOn(['limits' => ['emails_per_month' => 1]]);
    ['workspace' => $workspace, 'mailbox' => $mailbox] = readyCampaign(3);
    subscribe($workspace, $plan);
    fakeTransport();

    SendCampaignEmail::dispatchSync(CampaignLead::query()->first()->id, $mailbox->id, 1);
    expect(EmailMessage::count())->toBe(1);

    Bus::fake();
    expect(app(SendScheduler::class)->tick())->toBe(0)
        ->and(app(Entitlements::class)->sendingBlockedReason($workspace))->toContain('This month\'s email limit (1) is used up');
});

it('never sends from a suspended workspace, billing or not', function () {
    $transport = fakeTransport();
    ['workspace' => $workspace, 'mailbox' => $mailbox] = readyCampaign(1);
    $workspace->forceFill(['suspended_at' => now(), 'suspension_reason' => 'Spam complaints.'])->save();

    SendCampaignEmail::dispatchSync(CampaignLead::sole()->id, $mailbox->id, 1);

    expect($transport->sent)->toBe([])
        ->and(app(Entitlements::class)->sendingBlockedReason($workspace->refresh()))->toBe('This workspace is suspended: Spam complaints. Contact support.');
});

it('limits modules to the plan', function () {
    $plan = billingOn(['modules' => []]);
    ['workspace' => $workspace, 'campaign' => $campaign] = readyCampaign(1, launch: false);
    subscribe($workspace, $plan);
    actingInWorkspace(workspace: $workspace);

    expect(app(Entitlements::class)->allowsModule($workspace, 'ai'))->toBeFalse();

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertActionDisabled('aiPersonalize');
});

it('uses small limits for workspaces without a usable plan', function () {
    billingOn();
    $workspace = actingInWorkspace();

    expect(app(Entitlements::class)->limit($workspace, 'mailboxes'))->toBe(1)
        ->and(app(Entitlements::class)->sendingBlockedReason($workspace))->toBe('No plan yet. Choose one on the Billing page to start sending.');
});
