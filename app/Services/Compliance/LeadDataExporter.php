<?php

namespace App\Services\Compliance;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Everything stored about one lead, for a data subject access request
 * (GDPR art. 15 / 20): profile, lists, tags, timeline, campaigns, emails
 * sent with their engagement, replies, AI content and suppression entries.
 */
class LeadDataExporter
{
    /**
     * @return array<string, mixed>
     */
    public function export(Lead $lead): array
    {
        $workspaceId = $lead->workspace_id;
        $leadId = $lead->getKey();
        $time = fn ($value): ?string => $value ? (string) $value : null;

        return [
            'exported_at' => now()->toIso8601String(),
            'lead' => [
                ...collect(Lead::FIELDS)->mapWithKeys(fn (string $field): array => [$field => $lead->{$field}])->all(),
                'status' => $lead->status->value,
                'custom_fields' => $lead->custom_fields ?? [],
                'created_at' => $lead->created_at?->toIso8601String(),
                'updated_at' => $lead->updated_at?->toIso8601String(),
            ],
            'lists' => $lead->lists()->pluck('name')->all(),
            'tags' => $lead->tags()->pluck('name')->all(),
            'campaigns' => DB::table('campaign_leads')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_leads.campaign_id')
                ->where('campaign_leads.lead_id', $leadId)
                ->orderBy('campaign_leads.id')
                ->get(['campaigns.name as campaign', 'campaign_leads.status', 'campaign_leads.steps_sent', 'campaign_leads.created_at as added_at'])
                ->map(fn (object $row): array => [...(array) $row, 'added_at' => $time($row->added_at)])
                ->all(),
            'emails_sent' => DB::table('email_messages')
                ->where('workspace_id', $workspaceId)
                ->where('lead_id', $leadId)
                ->orderBy('id')
                ->get(['subject', 'sent_at', 'opened_at', 'open_count', 'clicked_at', 'click_count', 'replied_at', 'bounced_at', 'unsubscribed_at', 'complained_at'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'conversations' => DB::table('inbox_messages')
                ->join('inbox_threads', 'inbox_threads.id', '=', 'inbox_messages.inbox_thread_id')
                ->where('inbox_threads.lead_id', $leadId)
                ->orderBy('inbox_messages.id')
                ->get(['inbox_messages.direction', 'inbox_messages.from_email', 'inbox_messages.to_email', 'inbox_messages.subject', 'inbox_messages.body', 'inbox_messages.sent_at'])
                ->map(fn (object $row): array => [...(array) $row, 'sent_at' => $time($row->sent_at)])
                ->all(),
            'ai_content' => DB::table('ai_generations')
                ->where('lead_id', $leadId)
                ->orderBy('id')
                ->get(['type', 'status', 'output', 'created_at'])
                ->map(fn (object $row): array => [...(array) $row, 'created_at' => $time($row->created_at)])
                ->all(),
            'activity' => $lead->activities()
                ->reorder('id')
                ->get(['type', 'description', 'created_at'])
                ->map(fn ($activity): array => [
                    'type' => $activity->type->value,
                    'description' => $activity->description,
                    'at' => $activity->created_at?->toIso8601String(),
                ])
                ->all(),
            'suppression' => DB::table('suppressions')
                ->where('workspace_id', $workspaceId)
                ->where(fn ($query) => $query
                    ->where(fn ($q) => $q->where('type', 'email')->where('value', strtolower($lead->email)))
                    ->orWhere(fn ($q) => $q->where('type', 'domain')->where('value', strtolower((string) substr(strrchr($lead->email, '@') ?: '', 1)))))
                ->get(['type', 'value', 'reason', 'created_at'])
                ->map(fn (object $row): array => [...(array) $row, 'created_at' => $time($row->created_at)])
                ->all(),
        ];
    }

    public function toJson(Lead $lead): string
    {
        return json_encode($this->export($lead), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
