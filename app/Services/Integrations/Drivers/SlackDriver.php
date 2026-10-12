<?php

namespace App\Services\Integrations\Drivers;

use App\Models\Integration;
use App\Services\Integrations\IntegrationException;

/**
 * Posts a message through a Slack incoming webhook (hooks.slack.com only).
 */
class SlackDriver extends BaseDriver
{
    public function deliver(Integration $integration, string $event, array $payload): ?int
    {
        $url = (string) $integration->setting('url');

        if (! str_starts_with($url, 'https://hooks.slack.com/')) {
            throw new IntegrationException('Use a Slack incoming webhook URL (https://hooks.slack.com/...).', retryable: false);
        }

        return $this->send(fn () => $this->http()->post($url, ['text' => $this->headline($event, $payload)]))->status();
    }
}
