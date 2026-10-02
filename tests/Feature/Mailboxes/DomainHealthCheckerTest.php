<?php

use App\Enums\DnsCheckStatus;
use App\Enums\MailProvider;
use App\Models\EmailAccount;
use App\Models\SendingDomain;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\DomainHealthChecker;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);
    $this->checker = app(DomainHealthChecker::class);
});

it('passes a fully configured domain', function () {
    $this->dns->healthy('acme.com');

    $checks = $this->checker->check('acme.com', [MailProvider::Google]);

    expect(collect($checks)->pluck('status')->unique()->all())->toBe(['pass']);
});

it('suggests SPF and DMARC records when missing', function () {
    $this->dns->mx['acme.com'] = ['mx.acme.com'];

    $checks = $this->checker->check('acme.com', [MailProvider::Google]);

    expect($checks['spf']['status'])->toBe('fail')
        ->and($checks['spf']['fix'])->toBe(['type' => 'TXT', 'host' => '@', 'value' => 'v=spf1 include:_spf.google.com ~all'])
        ->and($checks['dmarc']['status'])->toBe('fail')
        ->and($checks['dmarc']['fix'])->toBe(['type' => 'TXT', 'host' => '_dmarc', 'value' => 'v=DMARC1; p=none; rua=mailto:dmarc@acme.com'])
        ->and($checks['dkim']['status'])->toBe('fail')
        ->and($checks['dkim']['help'])->toContain('Google Admin');
});

it('flags multiple SPF records and offers a merged one', function () {
    $this->dns->txt['acme.com'] = ['v=spf1 include:mailgun.org ~all', 'v=spf1 include:_spf.google.com -all'];

    $spf = $this->checker->check('acme.com', [MailProvider::Google])['spf'];

    expect($spf['status'])->toBe('fail')
        ->and($spf['summary'])->toContain('More than one SPF record')
        ->and($spf['fix']['value'])->toBe('v=spf1 include:mailgun.org include:_spf.google.com ~all');
});

it('warns when SPF does not authorize the provider', function () {
    $this->dns->txt['acme.com'] = ['v=spf1 include:mailgun.org ~all'];

    $spf = $this->checker->check('acme.com', [MailProvider::Microsoft])['spf'];

    expect($spf['status'])->toBe('warning')
        ->and($spf['fix']['value'])->toBe('v=spf1 include:mailgun.org include:spf.protection.outlook.com ~all');
});

it('fails SPF that allows everyone', function () {
    $this->dns->txt['acme.com'] = ['v=spf1 +all'];

    expect($this->checker->check('acme.com')['spf']['status'])->toBe('fail');
});

it('finds DKIM on a custom selector and ignores revoked keys', function () {
    $this->dns->txt['default._domainkey.acme.com'] = ['v=DKIM1; p='];
    $this->dns->txt['mykey._domainkey.acme.com'] = ['v=DKIM1; k=rsa; p=MIIBIj'];

    expect($this->checker->check('acme.com')['dkim']['status'])->toBe('fail')
        ->and($this->checker->check('acme.com', [], 'mykey')['dkim']['status'])->toBe('pass');
});

it('stores results and the worst status on the domain', function () {
    $this->dns->healthy('acme.com');
    unset($this->dns->txt['_dmarc.acme.com']);

    $account = EmailAccount::factory()->create(['email' => 'me@acme.com', 'provider' => MailProvider::Google]);
    $domain = $account->sendingDomain;

    $this->checker->checkAndStore($domain);

    expect($domain->refresh()->status)->toBe(DnsCheckStatus::Fail)
        ->and($domain->checkStatus('spf'))->toBe(DnsCheckStatus::Pass)
        ->and($domain->checkStatus('dmarc'))->toBe(DnsCheckStatus::Fail)
        ->and($domain->last_checked_at)->not->toBeNull();
});

it('links mailboxes to one domain per workspace', function () {
    $first = EmailAccount::factory()->create(['email' => 'a@acme.com']);
    $second = EmailAccount::factory()->for($first->workspace)->create(['email' => 'B@ACME.com']);

    expect($second->email)->toBe('b@acme.com')
        ->and($second->sending_domain_id)->toBe($first->sending_domain_id)
        ->and(SendingDomain::count())->toBe(1);
});
