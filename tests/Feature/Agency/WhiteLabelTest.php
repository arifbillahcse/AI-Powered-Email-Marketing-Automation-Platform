<?php

use App\Filament\App\Tenancy\WorkspaceSettings;
use App\Jobs\SendCampaignEmail;
use App\Mail\ClientReportMail;
use App\Models\CampaignLead;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Services\Agency\Branding;
use App\Services\Agency\ClientReport;
use App\Services\Dns\DnsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function () {
    config(['modules.white_label.enabled' => true, 'outreach.agency.domain_target' => 'app.softorio.com']);
    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);
});

function brandedAgency(array $attributes = []): Workspace
{
    return Workspace::factory()->create(array_merge([
        'name' => 'Softorio',
        'brand_name' => 'Acme Outreach',
        'brand_color' => '#0ea5e9',
    ], $attributes));
}

it('gives client workspaces the agency\'s brand', function () {
    $agency = brandedAgency();
    $client = Workspace::factory()->create(['parent_id' => $agency->id]);
    $branding = app(Branding::class);

    expect($branding->for($client))
        ->name->toBe('Acme Outreach')
        ->color->toBe('#0ea5e9')
        ->and($branding->for(Workspace::factory()->create()))->toBeNull();
});

it('drops the brand when the plan doesn\'t include white label', function () {
    $agency = brandedAgency();
    subscribe($agency, billingOn(['modules' => ['ai']]));

    expect(app(Branding::class)->for($agency))->toBeNull();
});

it('shows the brand in the panel', function () {
    $agency = brandedAgency();
    $user = User::factory()->create();
    actingInWorkspace($user, workspace: $agency);

    $this->get("/app/{$agency->slug}")
        ->assertOk()
        ->assertSee('Acme Outreach');
});

it('brands the sign-in page on a verified custom domain only', function () {
    $agency = brandedAgency(['custom_domain' => 'app.acme.test']);

    $this->get('http://app.acme.test/app/login')->assertOk()->assertDontSee('Acme Outreach');

    $agency->forceFill(['custom_domain_verified_at' => now()])->save();

    $this->get('http://app.acme.test/app/login')->assertOk()->assertSee('Acme Outreach');
});

it('verifies a custom domain by its CNAME', function () {
    $agency = actingInWorkspace();
    $agency->update(['custom_domain' => 'App.Acme.test']);

    expect($agency->refresh()->custom_domain)->toBe('app.acme.test');

    Livewire::test(WorkspaceSettings::class)
        ->callAction('verifyDomain')
        ->assertNotified('Not verified yet');

    $this->dns->cname['app.acme.test'] = 'app.softorio.com.';

    Livewire::test(WorkspaceSettings::class)
        ->callAction('verifyDomain')
        ->assertNotified('Domain verified');

    expect($agency->refresh()->custom_domain_verified_at)->not->toBeNull();

    // Changing the domain needs a new check.
    $agency->update(['custom_domain' => 'portal.acme.test']);
    expect($agency->refresh()->custom_domain_verified_at)->toBeNull();
});

it('saves branding from workspace settings', function () {
    $agency = actingInWorkspace();

    Livewire::test(WorkspaceSettings::class)
        ->fillForm(['brand_name' => 'Acme Outreach', 'brand_color' => '#123abc', 'custom_domain' => 'app.acme.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($agency->refresh())
        ->brand_name->toBe('Acme Outreach')
        ->brand_color->toBe('#123abc')
        ->custom_domain->toBe('app.acme.test');

    Livewire::test(WorkspaceSettings::class)
        ->fillForm(['brand_color' => 'red; background: url(x)', 'custom_domain' => 'app.softorio.com'])
        ->call('save')
        ->assertHasFormErrors(['brand_color', 'custom_domain']);
});

it('hides branding settings in client workspaces', function () {
    $agency = brandedAgency();
    actingInWorkspace(workspace: Workspace::factory()->create(['parent_id' => $agency->id]));

    Livewire::test(WorkspaceSettings::class)->assertDontSee('Branding (white label)');
});

it('shows a branded client report behind its secret link', function () {
    fakeTransport();
    ['workspace' => $workspace, 'mailbox' => $mailbox, 'campaign' => $campaign] = readyCampaign();
    $workspace->forceFill(['parent_id' => brandedAgency()->id])->save();
    SendCampaignEmail::dispatchSync(CampaignLead::sole()->id, $mailbox->id, 1);

    $reports = app(ClientReport::class);
    $url = $reports->url($workspace);

    $this->get($url)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Acme Outreach')
        ->assertSee($workspace->name)
        ->assertSee($campaign->name)
        ->assertSee('#0ea5e9', false);

    $reports->rotate($workspace);

    $this->get($url)->assertNotFound();
    $this->get('/reports/'.str_repeat('a', 40))->assertNotFound();
});

it('emails weekly reports on Mondays, once', function () {
    Mail::fake();
    $workspace = brandedAgency(['report_frequency' => 'weekly', 'report_recipients' => ['ceo@acme.test']]);

    Carbon::setTestNow(Carbon::parse('next tuesday 07:00'));
    $this->artisan('reports:send')->assertSuccessful();
    Mail::assertNothingSent();

    Carbon::setTestNow(Carbon::parse('next monday 07:00'));
    $this->artisan('reports:send')->assertSuccessful();
    $this->artisan('reports:send')->assertSuccessful();

    Mail::assertSent(ClientReportMail::class, 1);
    Mail::assertSent(ClientReportMail::class, fn (ClientReportMail $mail): bool => $mail->hasTo('ceo@acme.test')
        && $mail->senderName === 'Acme Outreach'
        && str_contains($mail->url, '/reports/'));

    expect($workspace->refresh()->report_last_sent_at)->not->toBeNull();
});

it('names the brand in invitation emails', function () {
    $agency = brandedAgency();
    $invitation = WorkspaceInvitation::query()->make(['email' => 'ceo@acme.test', 'role' => 'client']);
    $invitation->workspace()->associate($agency);
    $token = $invitation->issueToken();
    $invitation->save();

    $mail = (new WorkspaceInvitationNotification($invitation->refresh(), $token))->toMail(User::factory()->make());

    expect($mail->subject)->toContain('on Acme Outreach');
});
