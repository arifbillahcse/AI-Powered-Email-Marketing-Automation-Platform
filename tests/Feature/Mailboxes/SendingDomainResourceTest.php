<?php

use App\Enums\DnsCheckStatus;
use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\SendingDomains\Pages\CreateSendingDomain;
use App\Filament\App\Resources\SendingDomains\Pages\ListSendingDomains;
use App\Filament\App\Resources\SendingDomains\Pages\ViewSendingDomain;
use App\Jobs\CheckSendingDomain;
use App\Models\EmailAccount;
use App\Models\SendingDomain;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\TrackingDomainVerifier;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);
});

it('adds a domain and checks it right away', function () {
    $this->dns->healthy('softorio.com');
    $workspace = actingInWorkspace();

    Livewire::test(CreateSendingDomain::class)
        ->fillForm(['name' => 'Softorio.com'])
        ->call('create')
        ->assertHasNoFormErrors();

    $domain = SendingDomain::sole();

    expect($domain->workspace_id)->toBe($workspace->id)
        ->and($domain->name)->toBe('softorio.com')
        ->and($domain->status)->toBe(DnsCheckStatus::Pass);
});

it('re-checks from the table and shows copy-paste fixes', function () {
    $workspace = actingInWorkspace();
    $domain = SendingDomain::factory()->for($workspace)->create(['name' => 'softorio.com']);
    $this->dns->mx['softorio.com'] = ['mx.softorio.com'];

    Livewire::test(ListSendingDomains::class)
        ->callAction(TestAction::make('checkNow')->table($domain))
        ->assertNotified();

    expect($domain->refresh()->status)->toBe(DnsCheckStatus::Fail);

    Livewire::test(ViewSendingDomain::class, ['record' => $domain->getRouteKey()])
        ->assertOk()
        ->assertSee('v=DMARC1; p=none; rua=mailto:dmarc@softorio.com')
        ->assertSee('v=spf1 a mx ~all');
});

it('lets clients run checks but not add domains', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    $domain = SendingDomain::factory()->for($workspace)->create();

    Livewire::test(ListSendingDomains::class)
        ->assertActionVisible(TestAction::make('checkNow')->table($domain))
        ->assertActionHidden('create');
});

it('queues checks for stale domains every day', function () {
    Queue::fake();
    $stale = SendingDomain::factory()->create(['last_checked_at' => now()->subDay()]);
    $never = SendingDomain::factory()->create();
    SendingDomain::factory()->create(['last_checked_at' => now()->subHour()]);

    $this->artisan('domains:check')->assertSuccessful();

    Queue::assertPushed(CheckSendingDomain::class, 2);
    Queue::assertPushed(CheckSendingDomain::class, fn ($job) => $job->domain->is($stale));
    Queue::assertPushed(CheckSendingDomain::class, fn ($job) => $job->domain->is($never));
});

it('verifies a tracking domain CNAME', function () {
    config(['outreach.tracking.cname_target' => 'track.outreach.test']);
    $account = EmailAccount::factory()->create(['tracking_domain' => 'Track.Softorio.com']);
    $verifier = app(TrackingDomainVerifier::class);

    expect($account->tracking_domain)->toBe('track.softorio.com')
        ->and($verifier->verify($account))->toContain('No CNAME record');

    $this->dns->cname['track.softorio.com'] = 'elsewhere.example.com';
    expect($verifier->verify($account))->toContain('must point to track.outreach.test');

    $this->dns->cname['track.softorio.com'] = 'track.outreach.test.';
    expect($verifier->verify($account))->toBeNull()
        ->and($account->refresh()->tracking_domain_verified_at)->not->toBeNull();

    $account->update(['tracking_domain' => 'links.softorio.com']);
    expect($account->refresh()->tracking_domain_verified_at)->toBeNull();
});
