<?php

namespace App\Services\Integrations\Drivers;

use App\Models\Integration;
use App\Services\Integrations\IntegrationException;
use App\Services\Mail\HostGuard;
use App\Services\Mail\MailboxConnectionException;

/**
 * POSTs the event as JSON, signed like Stripe:
 * X-Outreach-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "t.body" with the secret>.
 */
class WebhookDriver extends BaseDriver
{
    public function __construct(
        protected HostGuard $guard,
    ) {}

    public function deliver(Integration $integration, string $event, array $payload): ?int
    {
        $url = (string) $integration->setting('url');
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! preg_match('#^https?://#i', $url) || $host === '') {
            throw new IntegrationException('The webhook URL isn\'t valid.', retryable: false);
        }

        try {
            $this->guard->assertAllowed($host);
        } catch (MailboxConnectionException $exception) {
            throw new IntegrationException($exception->getMessage(), retryable: false);
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = time();

        return $this->send(fn () => $this->http()
            ->withHeaders([
                'X-Outreach-Event' => $event,
                'X-Outreach-Signature' => 't='.$timestamp.',v1='.self::sign($body, $timestamp, (string) $integration->setting('secret')),
            ])
            ->withBody($body, 'application/json')
            ->post($url))->status();
    }

    public static function sign(string $body, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
