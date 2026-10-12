<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\SendingDomains\Pages\ListSendingDomains;
use App\Filament\App\Resources\SendingDomains\Pages\ViewSendingDomain;
use App\Jobs\CheckDomainBlacklists;
use App\Models\EmailAccount;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Compliance\BlacklistChecker;
use App\Services\Dns\DnsResolver;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);
    $this->dns->a['mail.softorio.com'] = ['203.0.113.10', '10.0.0.5']; // the private one is skipped
});

function domainWithMailbox(Workspace $workspace): SendingDomain
{
    EmailAccount::factory()->for($workspace)->create(['email' => 'arif@softorio.com', 'smtp_host' => 'mail.softorio.com']);

    return SendingDomain::query()->where('name', 'softorio.com')->sole();
}

it('finds a clean domain and its mail server IPs', function () {
    $domain = domainWithMailbox(Workspace::factory()->create());

    $result = app(BlacklistChecker::class)->checkAndStore($domain);

    expect($result['listings'])->toBe([])
        ->and($result['ips'])->toBe(['203.0.113.10'])
        ->and($result['checked'])->toContain('Spamhaus DBL', 'Spamhaus ZEN', 'SpamCop')
        ->and($domain->refresh()->blacklist_status)->toBe(BlacklistChecker::CLEAN)
        ->and($domain->blacklist_checked_at)->not->toBeNull();
});

it('reports listings and alerts owners and admins once', function () {
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace->addMember($owner, WorkspaceRole::Owner);
    $workspace->addMember($member, WorkspaceRole::Member);
    $domain = domainWithMailbox($workspace);

    $this->dns->a['softorio.com.dbl.spamhaus.org'] = ['127.0.1.2'];
    $this->dns->a['10.113.0.203.bl.spamcop.net'] = ['127.0.0.2'];

    $checker = app(BlacklistChecker::class);
    $checker->checkAndStore($domain);
    $checker->checkAndStore($domain->refresh()); // still listed: no second alert

    expect($domain->refresh()->blacklist_status)->toBe(BlacklistChecker::LISTED)
        ->and($domain->blacklist['listings'])->toBe([
            ['list' => 'Spamhaus DBL', 'target' => 'softorio.com'],
            ['list' => 'SpamCop', 'target' => '203.0.113.10'],
        ])
        ->and($owner->notifications()->count())->toBe(1)
        ->and($owner->notifications()->sole()->data['title'])->toBe('softorio.com is on a blacklist')
        ->and($member->notifications()->count())->toBe(0);
});

it('never reports a refused query as a listing', function () {
    $domain = domainWithMailbox(Workspace::factory()->create());
    $this->dns->a['softorio.com.dbl.spamhaus.org'] = ['127.255.255.254']; // public resolver refused
    $this->dns->a['softorio.com.multi.uribl.com'] = ['127.0.0.1'];

    $result = app(BlacklistChecker::class)->checkAndStore($domain);

    expect($result['listings'])->toBe([])
        ->and($result['unknown'])->toBe(['Spamhaus DBL', 'URIBL'])
        ->and($domain->refresh()->blacklist_status)->toBe(BlacklistChecker::CLEAN);
});

it('checks every domain with mailboxes daily', function () {
    Queue::fake();
    domainWithMailbox(Workspace::factory()->create());
    SendingDomain::factory()->create(); // no mailboxes

    $this->artisan('domains:blacklists')->expectsOutputToContain('Queued 1 blacklist checks.')->assertSuccessful();

    Queue::assertPushed(CheckDomainBlacklists::class, 1);
});

it('shows blacklist status and checks on demand', function () {
    $workspace = actingInWorkspace();
    $domain = domainWithMailbox($workspace);
    $this->dns->a['softorio.com.dbl.spamhaus.org'] = ['127.0.1.2'];

    Livewire::test(ViewSendingDomain::class, ['record' => $domain->getRouteKey()])
        ->assertSee('Not checked')
        ->callAction('checkBlacklists')
        ->assertNotified('Blacklist check queued');

    expect($domain->refresh()->blacklist_status)->toBe(BlacklistChecker::LISTED)
        ->and(auth()->user()->notifications()->get()->pluck('data.title'))->toContain('Blacklist check for softorio.com', 'softorio.com is on a blacklist');

    Livewire::test(ListSendingDomains::class)->assertSee('Listed');
    Livewire::test(ViewSendingDomain::class, ['record' => $domain->getRouteKey()])->assertSee('Spamhaus DBL: softorio.com');
});
