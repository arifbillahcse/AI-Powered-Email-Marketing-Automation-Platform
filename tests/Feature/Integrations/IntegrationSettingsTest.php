<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\ApiTokens\Pages\ManageApiTokens;
use App\Filament\App\Resources\Integrations\Pages\ManageIntegrations;
use App\Jobs\DeliverIntegrationEvent;
use App\Models\ApiToken;
use App\Models\Integration;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('creates an API key and shows it once', function () {
    actingInWorkspace();

    Livewire::test(ManageApiTokens::class)
        ->callAction('createKey', data: ['name' => 'Zapier', 'abilities' => ['read'], 'expires' => '30'])
        ->assertHasNoActionErrors()
        ->assertNotified('API key created: copy it now');

    $token = ApiToken::sole();

    expect($token->abilities)->toBe(['read'])
        ->and($token->expires_at->isSameDay(now()->addDays(30)))->toBeTrue();
});

it('keeps API keys and integrations from members', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Member);

    $this->get(ManageApiTokens::getUrl(tenant: $workspace))->assertForbidden();
    $this->get(ManageIntegrations::getUrl(tenant: $workspace))->assertForbidden();
});

it('adds a Telegram integration and never sends its token back to the browser', function () {
    actingInWorkspace();

    Livewire::test(ManageIntegrations::class)
        ->callAction('create', data: [
            'type' => 'telegram',
            'name' => 'Sales chat',
            'config' => ['bot_token' => '123:secret-token', 'chat_id' => '-100'],
            'events' => ['reply.interested'],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $integration = Integration::sole();
    expect($integration->setting('bot_token'))->toBe('123:secret-token');

    // Editing without a new token keeps the saved one.
    Livewire::test(ManageIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->assertDontSee('123:secret-token')
        ->fillForm(['config' => ['chat_id' => '-200'], 'events' => ['reply.interested', 'lead.replied']])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($integration->refresh()->setting('bot_token'))->toBe('123:secret-token')
        ->and($integration->setting('chat_id'))->toBe('-200')
        ->and($integration->events)->toBe(['reply.interested', 'lead.replied']);
});

it('gives webhooks a signing secret and sends a test event', function () {
    Queue::fake();
    actingInWorkspace();

    Livewire::test(ManageIntegrations::class)
        ->callAction('create', data: ['type' => 'webhook', 'name' => 'Zapier', 'config' => ['url' => 'https://hooks.zapier.com/hooks/catch/1/abc'], 'events' => ['lead.replied'], 'is_active' => true])
        ->assertHasNoActionErrors();

    $hook = Integration::sole();
    expect($hook->setting('secret'))->toStartWith('whsec_')
        ->and($hook->toArray())->not->toHaveKey('config');

    Livewire::test(ManageIntegrations::class)
        ->callAction(TestAction::make('test')->table($hook))
        ->assertNotified('Test sent');

    Queue::assertPushed(DeliverIntegrationEvent::class, fn (DeliverIntegrationEvent $job): bool => $job->payload['test'] === true);
});
