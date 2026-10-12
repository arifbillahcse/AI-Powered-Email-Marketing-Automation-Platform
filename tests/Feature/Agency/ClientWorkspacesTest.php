<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Pages\Billing;
use App\Filament\App\Pages\Clients;
use App\Models\EmailAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Agency\AgencyException;
use App\Services\Agency\ClientReport;
use App\Services\Agency\ClientWorkspaces;
use App\Services\Billing\AccountBanner;
use App\Services\Billing\Entitlements;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    config(['modules.white_label.enabled' => true]);
    Notification::fake();
});

it('creates a client workspace under the agency', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $agency = Workspace::factory()->create(['name' => 'Softorio']);
    $agency->addMember($owner, WorkspaceRole::Owner);
    $agency->addMember($admin, WorkspaceRole::Admin);
    $agency->addMember($member, WorkspaceRole::Member);

    $client = app(ClientWorkspaces::class)->create($agency, $owner, 'Acme Inc.', 'Asia/Dhaka', 'ceo@acme.test');

    expect($client)
        ->parent_id->toBe($agency->id)
        ->timezone->toBe('Asia/Dhaka')
        ->isClient()->toBeTrue()
        ->and($owner->roleIn($client))->toBe(WorkspaceRole::Owner)
        ->and($admin->roleIn($client))->toBe(WorkspaceRole::Admin)
        ->and($member->roleIn($client))->toBeNull()
        ->and(WorkspaceInvitation::query()->where('workspace_id', $client->id)->sole())
        ->email->toBe('ceo@acme.test')
        ->role->toBe(WorkspaceRole::Client);
});

it('refuses client workspaces without the module, for clients and over the limit', function () {
    $owner = User::factory()->create();
    $agency = Workspace::factory()->create();
    $agency->addMember($owner, WorkspaceRole::Owner);
    $clients = app(ClientWorkspaces::class);

    $client = $clients->create($agency, $owner, 'Acme', 'UTC');
    expect(fn () => $clients->create($client, $owner, 'Nested', 'UTC'))->toThrow(AgencyException::class, 'can\'t have clients');

    subscribe($agency, billingOn(['modules' => ['ai']]));
    expect(fn () => $clients->create($agency, $owner, 'Beta', 'UTC'))->toThrow(AgencyException::class, 'White Label');

    $agency->subscription()->delete();
    subscribe($agency, billingOn(['slug' => 'agency', 'modules' => ['white_label'], 'limits' => ['clients' => 1]]));
    expect(fn () => $clients->create($agency, $owner, 'Gamma', 'UTC'))->toThrow(AgencyException::class, '1 client workspaces');
});

it('bills client workspaces through the agency and pools their limits', function () {
    $agency = Workspace::factory()->create();
    $client = Workspace::factory()->create(['parent_id' => $agency->id]);
    $plan = billingOn(['modules' => ['white_label'], 'limits' => ['mailboxes' => 2, 'clients' => 5]]);
    subscribe($agency, $plan);
    $entitlements = app(Entitlements::class);

    EmailAccount::factory()->for($agency)->create();
    EmailAccount::factory()->for($client)->create();

    expect($entitlements->plan($client)?->id)->toBe($plan->id)
        ->and($entitlements->usage($client, 'mailboxes'))->toBe(2)
        ->and($entitlements->usage($agency, 'clients'))->toBe(1)
        ->and($entitlements->denies($agency, 'mailboxes'))->not->toBeNull()
        ->and($entitlements->sendingBlockedReason($client))->toBeNull();

    $agency->subscription()->update(['current_period_end' => now()->subMonth(), 'status' => 'expired']);

    expect($entitlements->sendingBlockedReason($client))->toContain('Contact your agency')
        ->and(app(AccountBanner::class)->for($client)['message'])->toContain('Contact your agency');
});

it('hides billing from client workspaces', function () {
    billingOn();
    $agency = Workspace::factory()->create();
    $client = Workspace::factory()->create(['parent_id' => $agency->id]);
    actingInWorkspace(workspace: $client);

    expect(Billing::canAccess())->toBeFalse();
});

it('lists clients with their results and adds new ones', function () {
    $agency = actingInWorkspace();
    $client = Workspace::factory()->create(['parent_id' => $agency->id, 'name' => 'Acme Inc.']);
    Workspace::factory()->create(['name' => 'Someone else']);

    Livewire::test(Clients::class)
        ->assertCanSeeTableRecords([$client])
        ->assertDontSee('Someone else')
        ->callAction('addClient', data: ['name' => 'Beta LLC', 'timezone' => 'UTC'])
        ->assertNotified('Beta LLC added');

    expect(Workspace::query()->where('name', 'Beta LLC')->sole()->parent_id)->toBe($agency->id);
});

it('sets up report emails and replaces the report link', function () {
    $agency = actingInWorkspace();
    $client = Workspace::factory()->create(['parent_id' => $agency->id]);
    $oldUrl = app(ClientReport::class)->url($client);

    Livewire::test(Clients::class)
        ->callAction(TestAction::make('reportSettings')->table($client), data: [
            'report_frequency' => 'weekly',
            'report_recipients' => ['CEO@acme.test'],
        ])
        ->callAction(TestAction::make('newReportLink')->table($client));

    $client->refresh();

    expect($client->report_frequency)->toBe('weekly')
        ->and($client->report_recipients)->toBe(['ceo@acme.test'])
        ->and(app(ClientReport::class)->url($client))->not->toBe($oldUrl);
});

it('keeps the clients page to agency owners and admins', function () {
    $agency = Workspace::factory()->create();
    actingInWorkspace(role: WorkspaceRole::Member, workspace: $agency);
    expect(Clients::canAccess())->toBeFalse();

    $client = Workspace::factory()->create(['parent_id' => Workspace::factory()->create()->id]);
    actingInWorkspace(workspace: $client);
    expect(Clients::canAccess())->toBeFalse();
});

it('is locked when the plan doesn\'t include white label', function () {
    $plan = billingOn(['modules' => ['ai']]);
    $workspace = actingInWorkspace();
    subscribe($workspace, $plan);

    Livewire::test(Clients::class)
        ->assertSee('Client workspaces aren\'t included in your plan')
        ->assertActionHidden('addClient');
});
