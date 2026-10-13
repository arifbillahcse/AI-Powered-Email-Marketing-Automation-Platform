<?php

use App\Models\Workspace;

beforeEach(function () {
    config(['marketing.enabled' => true, 'marketing.tagline' => 'Cold email that lands in the inbox.']);
});

it('shows the landing page with the enabled features', function () {
    config(['modules.white_label.enabled' => false]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Cold email that lands in the inbox.')
        ->assertSee('Inbox warmup')
        ->assertDontSee('Made for agencies')
        ->assertSee(route('filament.app.auth.register'), false);
});

it('lists active plans on the pricing page', function () {
    billingOn(['name' => 'Starter', 'price_monthly' => 2900, 'limits' => ['mailboxes' => 3], 'modules' => ['ai']]);
    billingOn(['name' => 'Hidden plan', 'slug' => 'hidden', 'is_active' => false]);

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('Starter')
        ->assertSee('$29.00')
        ->assertSee('3 mailboxes')
        ->assertSee('AI Personalization')
        ->assertDontSee('Hidden plan')
        ->assertDontSee('client workspaces');
});

it('says it is free when there are no plans', function () {
    $this->get('/pricing')->assertOk()->assertSee('early access');
});

it('serves the help center', function () {
    $this->get('/help')->assertOk()->assertSee('Getting started')->assertSee('DNS setup (SPF, DKIM, DMARC)');

    $this->get('/help/dns-setup')
        ->assertOk()
        ->assertSee('<h2>DKIM</h2>', false)
        ->assertSee('v=spf1 include:_spf.google.com ~all');

    $this->get('/help/no-such-article')->assertNotFound();
});

it('serves the legal pages and a sitemap', function () {
    config(['marketing.company' => 'Softorio Ltd', 'marketing.contact_email' => 'hello@softorio.com']);

    $this->get('/terms')->assertOk()->assertSee('Acceptable use')->assertSee('Softorio Ltd');
    $this->get('/privacy')->assertOk()->assertSee('hello@softorio.com');
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee(route('help.article', 'warmup'), false);
});

it('sends visitors to the app when the site is off', function () {
    config(['marketing.enabled' => false]);

    $this->get('/')->assertRedirect('/app');
    $this->get('/pricing')->assertRedirect('/app');
    $this->get('/sitemap.xml')->assertNotFound();
});

it('never shows the marketing site on an agency\'s custom domain', function () {
    Workspace::factory()->create(['custom_domain' => 'app.acme.test']);

    expect($this->get('http://app.acme.test/')->assertStatus(302)->headers->get('Location'))->toEndWith('/app/login');
    $this->get('http://app.acme.test/help')->assertOk();
});
