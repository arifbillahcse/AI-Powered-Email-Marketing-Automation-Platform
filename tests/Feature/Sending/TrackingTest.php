<?php

use App\Enums\CampaignLeadStatus;
use App\Enums\LeadStatus;
use App\Enums\SuppressionReason;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\EmailMessage;
use App\Models\Suppression;
use App\Services\Sending\TrackingUrls;

beforeEach(function () {
    fakeTransport();
    ['mailbox' => $this->mailbox, 'leads' => $leads] = readyCampaign(campaign: ['track_opens' => true, 'track_clicks' => true]);
    $this->lead = $leads[0];

    $campaignLead = CampaignLead::sole();
    SendCampaignEmail::dispatchSync($campaignLead->id, $this->mailbox->id, 1);
    $this->message = EmailMessage::sole();
});

it('records opens with a pixel and no cookies', function () {
    $response = $this->get("/t/o/{$this->message->token}.gif");

    $response->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect($response->headers->getCookies())->toBe([]);

    $this->get("/t/o/{$this->message->token}.gif");

    $message = $this->message->refresh();

    expect($message->open_count)->toBe(2)
        ->and($message->opened_at)->not->toBeNull()
        ->and($this->lead->activities()->where('type', 'email_opened')->count())->toBe(1);
});

it('redirects signed clicks and refuses tampered ones', function () {
    $url = 'https://softorio.com/pricing';
    $tracked = app(TrackingUrls::class)->click($this->mailbox, $this->message->token, $url);

    $this->get($tracked)->assertRedirect($url);
    expect($this->message->refresh()->click_count)->toBe(1);

    $this->get("/t/c/{$this->message->token}?u=".urlencode('https://evil.test').'&s=nope')->assertNotFound();
    $this->get("/t/c/{$this->message->token}?u=javascript:alert(1)&s=".app(TrackingUrls::class)->signature($this->message->token, 'javascript:alert(1)'))->assertNotFound();
});

it('shows an unsubscribe page and unsubscribes on confirm', function () {
    $this->get("/u/{$this->message->token}")->assertOk()->assertSee('Unsubscribe?');

    $this->post("/u/{$this->message->token}")->assertOk()->assertSee("You're unsubscribed", escape: false);

    expect(Suppression::sole()->reason)->toBe(SuppressionReason::Unsubscribed)
        ->and(CampaignLead::sole()->status)->toBe(CampaignLeadStatus::Unsubscribed)
        ->and($this->lead->refresh()->status)->toBe(LeadStatus::Unsubscribed);
});

it('accepts RFC 8058 one-click unsubscribes without a session or CSRF token', function () {
    $this->post("/u/{$this->message->token}", ['List-Unsubscribe' => 'One-Click'])
        ->assertOk()
        ->assertContent('');

    // Idempotent.
    $this->post("/u/{$this->message->token}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(Suppression::count())->toBe(1);
});

it('handles unknown tokens gracefully', function () {
    $this->get('/u/'.str_repeat('a', 40))->assertOk()->assertSee('Link not recognised');
    $this->get('/t/o/'.str_repeat('a', 40).'.gif')->assertOk();
});
