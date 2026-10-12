<?php

namespace App\Services\Integrations\Drivers;

use App\Models\Integration;
use App\Services\Integrations\IntegrationException;

/**
 * Creates or updates the lead as a HubSpot contact (matched by email),
 * using a private app token with the crm.objects.contacts.write scope.
 */
class HubSpotDriver extends BaseDriver
{
    public function deliver(Integration $integration, string $event, array $payload): ?int
    {
        $lead = $payload['lead'] ?? null;

        if (! $lead || blank($lead['email'] ?? null)) {
            return null; // Nothing to sync for events without a lead.
        }

        $properties = array_filter([
            'email' => $lead['email'],
            'firstname' => $lead['first_name'] ?? null,
            'lastname' => $lead['last_name'] ?? null,
            'company' => $lead['company'] ?? null,
            'jobtitle' => $lead['title'] ?? null,
            'phone' => $lead['phone'] ?? null,
            'website' => $lead['website'] ?? null,
            'city' => $lead['city'] ?? null,
            'country' => $lead['country'] ?? null,
            'hs_lead_status' => in_array($event, ['reply.interested', 'lead.replied'], true) ? 'OPEN' : null,
        ], fn ($value): bool => filled($value));

        $token = (string) $integration->setting('token');

        if ($token === '') {
            throw new IntegrationException('Add your HubSpot private app token.', retryable: false);
        }

        return $this->send(fn () => $this->http()->withToken($token)->post('https://api.hubapi.com/crm/v3/objects/contacts/batch/upsert', [
            'inputs' => [['idProperty' => 'email', 'id' => $lead['email'], 'properties' => $properties]],
        ]))->status();
    }
}
