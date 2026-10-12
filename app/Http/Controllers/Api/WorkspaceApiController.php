<?php

namespace App\Http\Controllers\Api;

use App\Enums\CampaignStatus;
use App\Enums\InboxMessageStatus;
use App\Models\Campaign;
use App\Models\EmailMessage;
use App\Models\InboxMessage;
use App\Models\LeadList;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Leads\SuppressionList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Everything else in API v1: the workspace, lists, campaigns, replies and
 * the suppression list.
 */
class WorkspaceApiController extends ApiController
{
    public function me(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $token = $request->attributes->get('api_token');

        return response()->json(['data' => [
            'workspace' => ['id' => $workspace->getKey(), 'name' => $workspace->name, 'timezone' => $workspace->timezone],
            'api_key' => ['name' => $token->name, 'abilities' => $token->abilities],
        ]]);
    }

    public function lists(Request $request): JsonResponse
    {
        $lists = LeadList::query()
            ->where('workspace_id', $this->workspace($request)->getKey())
            ->withCount('leads')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $lists->map(fn (LeadList $list): array => ['id' => $list->id, 'name' => $list->name, 'leads' => $list->leads_count])->all()]);
    }

    public function createList(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500']]);
        $workspaceId = $this->workspace($request)->getKey();

        $list = LeadList::query()->where('workspace_id', $workspaceId)->where('name', $data['name'])->first();
        $created = ! $list;

        if (! $list) {
            $list = new LeadList($data);
            $list->workspace_id = $workspaceId;
            $list->save();
        }

        return response()->json(['data' => ['id' => $list->id, 'name' => $list->name]], $created ? 201 : 200);
    }

    public function campaigns(Request $request): JsonResponse
    {
        $campaigns = Campaign::query()
            ->where('workspace_id', $this->workspace($request)->getKey())
            ->where('is_template', false)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $campaigns->map(fn (Campaign $campaign): array => $this->presentCampaign($campaign))->all()]);
    }

    public function showCampaign(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->presentCampaign($this->findCampaign($request, $id)) + ['stats' => $this->stats($id)]]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentCampaign(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'paused_reason' => $campaign->paused_reason,
            'launched_at' => $campaign->launched_at?->toIso8601String(),
        ];
    }

    public function pauseCampaign(Request $request, int $id): JsonResponse
    {
        $campaign = $this->findCampaign($request, $id);
        app(CampaignLauncher::class)->pause($campaign);

        return response()->json(['data' => $this->presentCampaign($campaign->refresh())]);
    }

    public function resumeCampaign(Request $request, int $id): JsonResponse
    {
        $campaign = $this->findCampaign($request, $id);

        if ($campaign->status !== CampaignStatus::Paused) {
            return response()->json(['message' => 'Only paused campaigns can be resumed.'], 422);
        }

        app(CampaignLauncher::class)->resume($campaign);

        return response()->json(['data' => $this->presentCampaign($campaign->refresh())]);
    }

    public function replies(Request $request): JsonResponse
    {
        $replies = InboxMessage::query()
            ->where('workspace_id', $this->workspace($request)->getKey())
            ->where('direction', InboxMessage::INBOUND)
            ->where('status', InboxMessageStatus::Received->value)
            ->when($request->query('since'), fn ($query, $since) => $query->where('created_at', '>=', now()->parse((string) $since)))
            ->when($request->query('category'), fn ($query, $category) => $query->where('ai_category', $category))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $replies->getCollection()->map(fn (InboxMessage $reply): array => [
                'id' => $reply->id,
                'thread_id' => $reply->inbox_thread_id,
                'from' => $reply->from_email,
                'subject' => $reply->subject,
                'text' => $reply->body,
                'auto_reply' => $reply->auto_reply,
                'category' => $reply->ai_category?->value,
                'summary' => $reply->ai_summary,
                'received_at' => $reply->sent_at?->toIso8601String(),
            ])->all(),
            'meta' => ['page' => $replies->currentPage(), 'per_page' => $replies->perPage(), 'total' => $replies->total()],
        ]);
    }

    public function suppress(Request $request): JsonResponse
    {
        $data = $request->validate(['entry' => ['required', 'string', 'max:255']]);

        try {
            $suppression = app(SuppressionList::class)->add($this->workspace($request)->getKey(), $data['entry']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => ['type' => $suppression->type->value, 'value' => $suppression->value]], 201);
    }

    protected function findCampaign(Request $request, int $id): Campaign
    {
        return Campaign::query()->where('workspace_id', $this->workspace($request)->getKey())->where('is_template', false)->findOrFail($id);
    }

    /**
     * @return array<string, int>
     */
    protected function stats(int $campaignId): array
    {
        $row = EmailMessage::query()
            ->where('campaign_id', $campaignId)
            ->whereNotNull('sent_at')
            ->toBase()
            ->selectRaw('count(*) as sent, count(opened_at) as opened, count(clicked_at) as clicked, count(replied_at) as replied, count(bounced_at) as bounced, count(unsubscribed_at) as unsubscribed')
            ->first();

        return collect(['sent', 'opened', 'clicked', 'replied', 'bounced', 'unsubscribed'])
            ->mapWithKeys(fn (string $key): array => [$key => (int) ($row->{$key} ?? 0)])
            ->all();
    }
}
