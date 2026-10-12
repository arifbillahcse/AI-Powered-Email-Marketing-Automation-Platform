<?php

use App\Enums\CampaignLeadStatus;
use App\Enums\EmailVerificationStatus;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\Lead;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Dns\DnsResolver;
use App\Services\Leads\EmailVerifier;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    $this->dns = new FakeDnsResolver;
    $this->dns->mx['acme.test'] = ['mx.acme.test'];
    $this->dns->a['legacy.test'] = ['203.0.113.7']; // no MX, but an A record
    $this->app->instance(DnsResolver::class, $this->dns);
});

it('classifies addresses without probing mail servers', function (string $email, EmailVerificationStatus $status, string $reason) {
    $result = app(EmailVerifier::class)->verify($email);

    expect($result['status'])->toBe($status)
        ->and($result['reason'])->toContain($reason);
})->with([
    'valid' => ['jane@acme.test', EmailVerificationStatus::Valid, 'domain accepts email'],
    'implicit MX' => ['jane@legacy.test', EmailVerificationStatus::Valid, 'domain accepts email'],
    'role' => ['sales@acme.test', EmailVerificationStatus::Risky, 'Role address'],
    'no mail server' => ['jane@nowhere.test', EmailVerificationStatus::Invalid, 'no mail server'],
    'disposable' => ['jane@mailinator.com', EmailVerificationStatus::Invalid, 'Disposable'],
    'syntax' => ['jane@@acme', EmailVerificationStatus::Invalid, 'Not a valid email'],
]);

it('asks a verification service when one is configured', function () {
    config(['outreach.verification.provider' => 'millionverifier', 'outreach.verification.api_key' => 'mv-key']);
    Http::fake(['api.millionverifier.com/*' => Http::sequence()
        ->push(['result' => 'ok'])
        ->push(['result' => 'catch_all'])
        ->push(['result' => 'invalid'])]);

    $verifier = app(EmailVerifier::class);

    expect($verifier->verify('a@acme.test')['status'])->toBe(EmailVerificationStatus::Valid)
        ->and($verifier->verify('b@acme.test')['status'])->toBe(EmailVerificationStatus::Risky)
        ->and($verifier->verify('c@acme.test')['status'])->toBe(EmailVerificationStatus::Invalid);
});

it('verifies a selection in the background and reports the results', function () {
    $workspace = actingInWorkspace();
    $good = Lead::factory()->for($workspace)->create(['email' => 'jane@acme.test']);
    $bad = Lead::factory()->for($workspace)->create(['email' => 'joe@nowhere.test']);

    Livewire::test(ListLeads::class)
        ->selectTableRecords([$good, $bad])
        ->callAction(TestAction::make('verifyEmails')->table()->bulk())
        ->assertNotified('Verifying 2 leads');

    expect($good->refresh()->verification_status)->toBe(EmailVerificationStatus::Valid)
        ->and($bad->refresh()->verification_status)->toBe(EmailVerificationStatus::Invalid)
        ->and($bad->verification_reason)->toBe('nowhere.test has no mail server.')
        ->and(auth()->user()->notifications()->sole()->data['title'])->toBe('Email verification finished');
});

it('verifies every unchecked lead from the header action', function () {
    $workspace = actingInWorkspace();
    Lead::factory()->for($workspace)->count(3)->create(['email' => fn () => fake()->unique()->userName().'@acme.test']);

    Livewire::test(ListLeads::class)->callAction('verifyUnchecked')->assertNotified('Verifying 3 leads');

    expect(Lead::query()->where('verification_status', 'valid')->count())->toBe(3);
});

it('is locked when the plan does not include it', function () {
    $plan = billingOn(['modules' => []]);
    $workspace = actingInWorkspace();
    subscribe($workspace, $plan);

    Livewire::test(ListLeads::class)->assertActionDisabled('verifyUnchecked');
});

it('never emails addresses verified as invalid', function () {
    ['campaign' => $campaign, 'leads' => $leads] = readyCampaign(3, launch: false);
    $leads[0]->forceFill(['verification_status' => EmailVerificationStatus::Invalid])->save();
    $leads[1]->forceFill(['verification_status' => EmailVerificationStatus::Valid])->save();

    expect(app(CampaignAudience::class)->count($campaign))->toBe(2);

    $campaign->update(['verified_only' => true]);
    expect(app(CampaignAudience::class)->count($campaign->refresh()))->toBe(1);
});

it('stops a lead found invalid after enrollment', function () {
    $transport = fakeTransport();
    ['mailbox' => $mailbox, 'leads' => $leads] = readyCampaign(1);
    $leads[0]->forceFill(['verification_status' => EmailVerificationStatus::Invalid])->save();

    SendCampaignEmail::dispatchSync(CampaignLead::sole()->id, $mailbox->id, 1);

    expect($transport->sent)->toBe([])
        ->and(CampaignLead::sole()->status)->toBe(CampaignLeadStatus::Stopped);
});
