<?php

namespace App\Services\Integrations\Drivers;

use App\Services\Integrations\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class BaseDriver implements Driver
{
    protected function http(): PendingRequest
    {
        return Http::timeout(10)->connectTimeout(5)->acceptJson()->withoutRedirecting()->withUserAgent('OutreachIntegrations/1.0');
    }

    /**
     * @param  callable(): Response  $request
     */
    protected function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new IntegrationException('Couldn\'t connect: '.Str::limit($exception->getMessage(), 200));
        }

        if (! $response->successful()) {
            // 4xx means our request is wrong (bad token, deleted sheet): don't retry.
            throw new IntegrationException(
                "HTTP {$response->status()}: ".Str::limit(trim(strip_tags($response->body())), 300),
                $response->status(),
                retryable: $response->serverError() || $response->status() === 429,
            );
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function headline(string $event, array $payload): string
    {
        $lead = $payload['lead'] ?? [];
        $who = trim(($lead['first_name'] ?? '').' '.($lead['last_name'] ?? '')) ?: ($lead['email'] ?? 'A lead');
        $company = filled($lead['company'] ?? null) ? " ({$lead['company']})" : '';
        $campaign = filled($payload['campaign']['name'] ?? null) ? " · {$payload['campaign']['name']}" : '';

        $what = match ($event) {
            'reply.interested' => '🔥 Interested reply from',
            'reply.classified' => 'Reply ('.($payload['reply']['category'] ?? 'other').') from',
            'lead.replied' => 'New reply from',
            'email.sent' => 'Email sent to',
            'email.opened' => 'Email opened by',
            'email.clicked' => 'Link clicked by',
            'email.bounced' => 'Bounce:',
            'lead.unsubscribed' => 'Unsubscribed:',
            default => $event.':',
        };

        $text = "{$what} {$who}{$company}{$campaign}";

        if (filled($payload['reply']['summary'] ?? null)) {
            $text .= "\n".$payload['reply']['summary'];
        } elseif (filled($payload['reply']['text'] ?? null)) {
            $text .= "\n".Str::limit((string) $payload['reply']['text'], 300);
        }

        return $text;
    }
}
