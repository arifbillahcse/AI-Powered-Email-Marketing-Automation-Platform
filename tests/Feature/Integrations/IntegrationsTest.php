<?php

use App\Enums\IntegrationType;
use App\Enums\WorkspaceRole;
use App\Jobs\DeliverIntegrationEvent;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\Integration;
use App\Models\IntegrationDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Integrations\Drivers\WebhookDriver;
use App\Services\Integrations\IntegrationEvents;
use App\Services\Mail\HostGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function integration(Workspace $workspace, IntegrationType $type, array $config, array $events = ['reply.interested']): Integration
{
    $integration = new Integration(['type' => $type, 'name' => $type->getLabel(), 'events' => $events]);
    $integration->workspace_id = $workspace->id;
    $integration->config = $config;
    $integration->save();

    return $integration;
}

function deliver(Integration $integration, string $event = 'reply.interested', ?array $payload = null): void
{
    (new DeliverIntegrationEvent($integration->id, $event, $payload ?? app(IntegrationEvents::class)->sample($integration->workspace_id)))->handle();
}

beforeEach(function () {
    $this->mock(HostGuard::class)->shouldReceive('assertAllowed'); // no DNS in tests
});

it('queues events only for active integrations that subscribe to them', function () {
    Queue::fake();
    $workspace = Workspace::factory()->create();
    integration($workspace, IntegrationType::Webhook, ['url' => 'https://example.test/hook', 'secret' => 's'], ['email.sent']);
    integration($workspace, IntegrationType::Webhook, ['url' => 'https://example.test/other', 'secret' => 's'], ['lead.replied']);
    integration($workspace, IntegrationType::Webhook, ['url' => 'https://example.test/off', 'secret' => 's'], ['email.sent'])->forceFill(['is_active' => false])->save();

    expect(app(IntegrationEvents::class)->fire($workspace->id, 'email.sent'))->toBe(1);
    Queue::assertPushed(DeliverIntegrationEvent::class, 1);
});

it('fires email.sent when a campaign email goes out', function () {
    fakeTransport();
    ['workspace' => $workspace, 'mailbox' => $mailbox, 'campaign' => $campaign] = readyCampaign(1);
    integration($workspace, IntegrationType::Webhook, ['url' => 'https://example.test/hook', 'secret' => 's'], ['email.sent']);
    Queue::fake();

    // Run the job directly: Queue::fake() would capture dispatchSync too.
    app()->call([new SendCampaignEmail(CampaignLead::sole()->id, $mailbox->id, 1), 'handle']);

    Queue::assertPushed(DeliverIntegrationEvent::class, fn (DeliverIntegrationEvent $job): bool => $job->event === 'email.sent'
        && $job->payload['campaign']['id'] === $campaign->id
        && $job->payload['email']['step'] === 1
        && filled($job->payload['lead']['email']));
});

it('signs webhook deliveries', function () {
    $workspace = Workspace::factory()->create();
    $hook = integration($workspace, IntegrationType::Webhook, ['url' => 'https://example.test/hook', 'secret' => 'whsec_test']);
    Http::fake(['example.test/*' => Http::response(['ok' => true])]);

    deliver($hook);

    Http::assertSent(function (Request $request): bool {
        preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $request->header('X-Outreach-Signature')[0], $match);

        return $request->header('X-Outreach-Event')[0] === 'reply.interested'
            && hash_equals(WebhookDriver::sign($request->body(), (int) $match[1], 'whsec_test'), $match[2])
            && $request['reply']['category'] === 'meeting_request';
    });

    expect($hook->refresh()->last_delivered_at)->not->toBeNull()
        ->and(IntegrationDelivery::sole()->success)->toBeTrue();
});

it('posts readable messages to Slack and Telegram', function () {
    $workspace = Workspace::factory()->create();
    Http::fake();

    deliver(integration($workspace, IntegrationType::Slack, ['url' => 'https://hooks.slack.com/services/T/B/x']));
    deliver(integration($workspace, IntegrationType::Telegram, ['bot_token' => '123:abc', 'chat_id' => '-100']));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hooks.slack.com/services/T/B/x'
        && str_contains($request['text'], 'Interested reply from Jane Doe (Example Inc)')
        && str_contains($request['text'], 'Wants a call on Tuesday.'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot123:abc/sendMessage' && $request['chat_id'] === '-100');
});

it('refuses Slack URLs that are not Slack', function () {
    $workspace = Workspace::factory()->create();
    $slack = integration($workspace, IntegrationType::Slack, ['url' => 'https://evil.test/collect']);
    Http::fake();

    deliver($slack);

    Http::assertNothingSent();
    expect($slack->refresh()->last_error)->toContain('hooks.slack.com');
});

it('upserts HubSpot contacts and adds Pipedrive persons with a note', function () {
    $workspace = Workspace::factory()->create();
    Http::fake([
        'api.hubapi.com/*' => Http::response(['status' => 'COMPLETE']),
        'acme.pipedrive.com/api/v1/persons/search*' => Http::response(['data' => ['items' => []]]),
        'acme.pipedrive.com/api/v1/persons?*' => Http::response(['data' => ['id' => 77]]),
        'acme.pipedrive.com/api/v1/notes*' => Http::response(['data' => ['id' => 1]]),
    ]);

    deliver(integration($workspace, IntegrationType::HubSpot, ['token' => 'pat-123']));
    deliver(integration($workspace, IntegrationType::Pipedrive, ['company_domain' => 'acme', 'token' => 'pd-123']));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'contacts/batch/upsert')
        && $request->hasHeader('Authorization', 'Bearer pat-123')
        && $request['inputs'][0]['id'] === 'jane@example.com'
        && $request['inputs'][0]['properties']['firstname'] === 'Jane');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/notes') && $request['person_id'] === 77);
});

it('appends a row to Google Sheets as a service account', function () {
    $workspace = Workspace::factory()->create();
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $privateKey);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.sheet']),
        'sheets.googleapis.com/*' => Http::response(['updates' => ['updatedRows' => 1]]),
    ]);

    deliver(integration($workspace, IntegrationType::GoogleSheets, [
        'spreadsheet_id' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789',
        'sheet_name' => 'Leads',
        'service_account' => json_encode(['client_email' => 'bot@project.iam.gserviceaccount.com', 'private_key' => $privateKey]),
    ]));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/values/Leads:append')
        && $request->hasHeader('Authorization', 'Bearer ya29.sheet')
        && $request['values'][0][2] === 'jane@example.com');
});

it('switches an integration off after 20 failures in a row', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->addMember($owner, WorkspaceRole::Owner);
    $hook = integration($workspace, IntegrationType::Webhook, ['url' => 'https://example.test/hook', 'secret' => 's']);
    $hook->forceFill(['failure_count' => 19])->save();
    Http::fake(['*' => Http::response('Not found', 404)]);

    deliver($hook);

    expect($hook->refresh()->is_active)->toBeFalse()
        ->and($hook->failure_count)->toBe(20)
        ->and($hook->last_error)->toContain('HTTP 404')
        ->and($owner->notifications()->sole()->data['title'])->toBe('Integration "Webhook (Zapier, Make, n8n, your app)" switched off');
});
