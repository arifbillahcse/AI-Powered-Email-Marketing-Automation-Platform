<?php

namespace App\Jobs;

use App\Enums\IntegrationType;
use App\Models\Integration;
use App\Models\IntegrationDelivery;
use App\Services\Compliance\WorkspaceAlerts;
use App\Services\Integrations\Drivers\Driver;
use App\Services\Integrations\Drivers\GoogleSheetsDriver;
use App\Services\Integrations\Drivers\HubSpotDriver;
use App\Services\Integrations\Drivers\PipedriveDriver;
use App\Services\Integrations\Drivers\SlackDriver;
use App\Services\Integrations\Drivers\TelegramDriver;
use App\Services\Integrations\Drivers\WebhookDriver;
use App\Services\Integrations\IntegrationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one event to one integration. Server errors and rate limits
 * are retried with backoff; after 20 failures in a row the integration is
 * switched off and owners/admins are told.
 */
class DeliverIntegrationEvent implements ShouldQueue
{
    use Queueable;

    public const MAX_FAILURES = 20;

    public int $tries = 4;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $integrationId,
        public string $event,
        public array $payload,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public static function driver(IntegrationType $type): Driver
    {
        return app(match ($type) {
            IntegrationType::Webhook => WebhookDriver::class,
            IntegrationType::Slack => SlackDriver::class,
            IntegrationType::Telegram => TelegramDriver::class,
            IntegrationType::HubSpot => HubSpotDriver::class,
            IntegrationType::Pipedrive => PipedriveDriver::class,
            IntegrationType::GoogleSheets => GoogleSheetsDriver::class,
        });
    }

    public function handle(): void
    {
        $integration = Integration::query()->find($this->integrationId);

        if (! $integration || (! $integration->is_active && ! ($this->payload['test'] ?? false))) {
            return;
        }

        try {
            $status = static::driver($integration->type)->deliver($integration, $this->event, $this->payload);
        } catch (IntegrationException $exception) {
            $this->record($integration, false, $exception->statusCode, $exception->getMessage());

            if ($exception->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 600);
            }

            return;
        }

        $this->record($integration, true, $status, null);
    }

    protected function record(Integration $integration, bool $success, ?int $status, ?string $error): void
    {
        $delivery = new IntegrationDelivery;
        $delivery->forceFill([
            'integration_id' => $integration->getKey(),
            'event' => $this->event,
            'success' => $success,
            'status_code' => $status,
            'error' => $error ? mb_substr($error, 0, 1000) : null,
            'created_at' => now(),
        ])->save();

        if ($success) {
            $integration->forceFill(['failure_count' => 0, 'last_delivered_at' => now(), 'last_error' => null])->save();

            return;
        }

        $failures = $integration->failure_count + 1;
        $integration->forceFill(['failure_count' => $failures, 'last_error' => mb_substr((string) $error, 0, 1000)])->save();

        if ($failures >= self::MAX_FAILURES && $integration->is_active) {
            $integration->forceFill(['is_active' => false])->save();

            app(WorkspaceAlerts::class)->send(
                $integration->workspace_id,
                "Integration \"{$integration->name}\" switched off",
                "It failed {$failures} times in a row. Last error: ".mb_substr((string) $error, 0, 200).' Fix it in Settings → Integrations and switch it back on.',
            );
        }
    }
}
