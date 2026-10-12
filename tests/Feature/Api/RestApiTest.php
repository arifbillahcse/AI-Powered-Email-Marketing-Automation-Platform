<?php

use App\Enums\CampaignStatus;
use App\Models\ApiToken;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Workspace;
use App\Services\Leads\SuppressionList;

function apiKey(Workspace $workspace, array $abilities = ['read', 'write']): string
{
    return ApiToken::issue($workspace, 'Test', $abilities)['plain'];
}

it('rejects missing, wrong and expired keys', function () {
    $workspace = Workspace::factory()->create();
    $expired = ApiToken::issue($workspace, 'Old', ['read'], expiresAt: now()->subDay())['plain'];

    $this->getJson('/api/v1/leads')->assertUnauthorized();
    $this->withToken('oak_nope')->getJson('/api/v1/leads')->assertUnauthorized();
    $this->withToken($expired)->getJson('/api/v1/leads')->assertUnauthorized();
});

it('stores only a hash of the key', function () {
    $workspace = Workspace::factory()->create();
    ['token' => $token, 'plain' => $plain] = ApiToken::issue($workspace, 'Zapier', ['read']);

    expect($token->token_hash)->toBe(hash('sha256', $plain))
        ->and($token->toArray())->not->toHaveKey('token_hash')
        ->and($plain)->toStartWith('oak_');
});

it('creates leads, or updates them by email', function () {
    $workspace = Workspace::factory()->create();
    $key = apiKey($workspace);

    $this->withToken($key)->postJson('/api/v1/leads', [
        'email' => 'Jane@Acme.test',
        'first_name' => 'Jane',
        'company' => 'Acme',
        'custom_fields' => ['team_size' => '12'],
        'list_names' => ['Zapier leads'],
        'tags' => ['webinar'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'jane@acme.test')
        ->assertJsonPath('data.lists.0.name', 'Zapier leads')
        ->assertJsonPath('data.tags.0', 'webinar');

    $this->withToken($key)->postJson('/api/v1/leads', ['email' => 'jane@acme.test', 'title' => 'CEO'])
        ->assertOk()
        ->assertJsonPath('data.title', 'CEO')
        ->assertJsonPath('data.first_name', 'Jane');

    $lead = Lead::sole();

    expect($lead->source)->toBe('api')
        ->and($lead->workspace_id)->toBe($workspace->id)
        ->and(LeadList::sole()->workspace_id)->toBe($workspace->id);
});

it('lists, filters, updates and deletes leads', function () {
    $workspace = Workspace::factory()->create();
    $key = apiKey($workspace);
    $jane = Lead::factory()->for($workspace)->create(['email' => 'jane@acme.test']);
    Lead::factory()->for($workspace)->count(2)->create();

    $this->withToken($key)->getJson('/api/v1/leads?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3);

    $this->withToken($key)->getJson('/api/v1/leads?email=jane@acme.test')->assertJsonPath('data.0.id', $jane->id);

    $this->withToken($key)->patchJson("/api/v1/leads/{$jane->id}", ['status' => 'interested'])
        ->assertOk()
        ->assertJsonPath('data.status', 'interested');

    $this->withToken($key)->patchJson("/api/v1/leads/{$jane->id}", ['status' => 'nonsense'])->assertUnprocessable();

    $this->withToken($key)->deleteJson("/api/v1/leads/{$jane->id}")->assertNoContent();
    expect(Lead::query()->whereKey($jane->id)->exists())->toBeFalse();
});

it('never shows another workspace\'s data', function () {
    $mine = Workspace::factory()->create();
    $theirs = Lead::factory()->for(Workspace::factory()->create())->create();
    $key = apiKey($mine);

    $this->withToken($key)->getJson("/api/v1/leads/{$theirs->id}")->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/leads/{$theirs->id}")->assertNotFound();
    $this->withToken($key)->getJson('/api/v1/leads')->assertJsonCount(0, 'data');
});

it('keeps read-only keys read-only', function () {
    $workspace = Workspace::factory()->create();
    $key = apiKey($workspace, ['read']);

    $this->withToken($key)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.workspace.id', $workspace->id);
    $this->withToken($key)->postJson('/api/v1/leads', ['email' => 'x@acme.test'])->assertForbidden();
});

it('shows campaigns with stats and pauses or resumes them', function () {
    fakeTransport();
    ['workspace' => $workspace, 'campaign' => $campaign] = readyCampaign(1);
    $key = apiKey($workspace);

    $this->withToken($key)->getJson('/api/v1/campaigns')->assertJsonPath('data.0.id', $campaign->id);
    $this->withToken($key)->getJson("/api/v1/campaigns/{$campaign->id}")->assertJsonPath('data.stats.sent', 0);

    $this->withToken($key)->postJson("/api/v1/campaigns/{$campaign->id}/pause")->assertJsonPath('data.status', 'paused');
    $this->withToken($key)->postJson("/api/v1/campaigns/{$campaign->id}/resume")->assertJsonPath('data.status', 'active');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Active);
});

it('adds suppressions and creates lists', function () {
    $workspace = Workspace::factory()->create();
    $key = apiKey($workspace);

    $this->withToken($key)->postJson('/api/v1/suppressions', ['entry' => 'competitor.test'])->assertCreated();
    $this->withToken($key)->postJson('/api/v1/suppressions', ['entry' => 'not an email@'])->assertUnprocessable();
    $this->withToken($key)->postJson('/api/v1/lists', ['name' => 'From API'])->assertCreated();
    $this->withToken($key)->getJson('/api/v1/lists')->assertJsonPath('data.0.name', 'From API');

    expect(app(SuppressionList::class)->isSuppressed($workspace->id, 'ceo@competitor.test'))->toBeTrue();
});

it('is blocked for suspended workspaces and plans without the API', function () {
    $workspace = Workspace::factory()->create();
    $key = apiKey($workspace);

    $plan = billingOn(['modules' => ['ai']]);
    subscribe($workspace, $plan);
    $this->withToken($key)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('message', 'API access isn\'t included in your plan.');

    config(['billing.enabled' => false]);
    $workspace->forceFill(['suspended_at' => now()])->save();
    $this->withToken($key)->getJson('/api/v1/me')->assertForbidden();
});
