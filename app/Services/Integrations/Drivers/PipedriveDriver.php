<?php

namespace App\Services\Integrations\Drivers;

use App\Models\Integration;
use App\Services\Integrations\IntegrationException;
use Illuminate\Support\Str;

/**
 * Finds the lead as a Pipedrive person (by email) or creates one, and adds
 * a note with what happened (e.g. the interested reply).
 */
class PipedriveDriver extends BaseDriver
{
    public function deliver(Integration $integration, string $event, array $payload): ?int
    {
        $lead = $payload['lead'] ?? null;

        if (! $lead || blank($lead['email'] ?? null)) {
            return null;
        }

        $company = Str::lower((string) $integration->setting('company_domain'));
        $token = (string) $integration->setting('token');

        if (! preg_match('/^[a-z0-9-]+$/', $company) || $token === '') {
            throw new IntegrationException('Add your Pipedrive company name (the part before .pipedrive.com) and API token.', retryable: false);
        }

        $base = "https://{$company}.pipedrive.com/api/v1";
        $query = ['api_token' => $token];

        $found = $this->send(fn () => $this->http()->get("{$base}/persons/search", [...$query, 'term' => $lead['email'], 'fields' => 'email', 'exact_match' => 'true']));
        $personId = $found->json('data.items.0.item.id');

        if (! $personId) {
            $created = $this->send(fn () => $this->http()->post("{$base}/persons?".http_build_query($query), [
                'name' => trim(($lead['first_name'] ?? '').' '.($lead['last_name'] ?? '')) ?: $lead['email'],
                'email' => [['value' => $lead['email'], 'primary' => true]],
                'phone' => filled($lead['phone'] ?? null) ? [['value' => $lead['phone'], 'primary' => true]] : [],
            ]));
            $personId = $created->json('data.id');
        }

        if (! $personId) {
            throw new IntegrationException('Pipedrive didn\'t return the person.');
        }

        return $this->send(fn () => $this->http()->post("{$base}/notes?".http_build_query($query), [
            'person_id' => $personId,
            'content' => nl2br(e($this->headline($event, $payload))),
        ]))->status();
    }
}
