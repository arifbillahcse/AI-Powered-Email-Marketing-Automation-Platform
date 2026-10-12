<?php

namespace App\Services\Integrations;

use App\Jobs\DeliverIntegrationEvent;
use App\Models\Campaign;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Workspace;

/**
 * Workspace events that integrations (webhooks, Slack, CRMs, sheets)
 * subscribe to. Each delivery is its own queued job, so a slow or broken
 * endpoint never holds up sending or reply processing.
 */
class IntegrationEvents
{
    public const EVENTS = [
        'email.sent' => 'Email sent',
        'email.opened' => 'Email opened (first open)',
        'email.clicked' => 'Link clicked (first click)',
        'email.bounced' => 'Email bounced',
        'lead.replied' => 'Lead replied',
        'reply.interested' => 'Interested reply (AI)',
        'reply.classified' => 'Reply sorted by AI (any category)',
        'lead.unsubscribed' => 'Lead unsubscribed or complained',
    ];

    /**
     * @param  array<string, mixed>  $data  Event-specific details (email, reply, ...)
     */
    public function fire(int $workspaceId, string $event, ?Lead $lead = null, array $data = [], ?int $campaignId = null): int
    {
        $integrations = Integration::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->get(['id', 'events'])
            ->filter(fn (Integration $integration): bool => in_array($event, $integration->events ?? [], true));

        if ($integrations->isEmpty()) {
            return 0;
        }

        $campaign = $campaignId ? Campaign::query()->find($campaignId) : null;
        $payload = $this->payload($workspaceId, $event, $lead, $data, $campaign);

        foreach ($integrations as $integration) {
            DeliverIntegrationEvent::dispatch($integration->getKey(), $event, $payload);
        }

        return $integrations->count();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function payload(int $workspaceId, string $event, ?Lead $lead, array $data = [], ?Campaign $campaign = null): array
    {
        return [
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'workspace' => ['id' => $workspaceId, 'name' => Workspace::query()->whereKey($workspaceId)->value('name')],
            'lead' => $lead ? self::lead($lead) : null,
            'campaign' => $campaign ? ['id' => $campaign->getKey(), 'name' => $campaign->name] : null,
            ...$data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lead(Lead $lead): array
    {
        return [
            'id' => $lead->getKey(),
            ...collect(Lead::FIELDS)->mapWithKeys(fn (string $field): array => [$field => $lead->{$field}])->all(),
            'status' => $lead->status->value,
            'custom_fields' => $lead->custom_fields ?? (object) [],
        ];
    }

    /**
     * A realistic example for "Send test".
     *
     * @return array<string, mixed>
     */
    public function sample(int $workspaceId): array
    {
        return $this->payload($workspaceId, 'reply.interested', null, [
            'lead' => ['id' => 0, 'email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Doe', 'company' => 'Example Inc', 'title' => 'Founder', 'status' => 'interested'],
            'reply' => ['subject' => 'Re: Quick question', 'text' => 'Sounds interesting, can we talk on Tuesday?', 'category' => 'meeting_request', 'summary' => 'Wants a call on Tuesday.'],
            'test' => true,
        ]);
    }
}
