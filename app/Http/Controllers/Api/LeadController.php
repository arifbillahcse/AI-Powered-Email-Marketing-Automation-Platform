<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadList;
use App\Services\Billing\Entitlements;
use App\Services\Integrations\IntegrationEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);

        $leads = Lead::query()
            ->where('workspace_id', $workspace->getKey())
            ->when($request->query('email'), fn ($query, $email) => $query->where('email', Str::lower((string) $email)))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('list_id'), fn ($query, $listId) => $query->whereHas('lists', fn ($lists) => $lists->where('lead_lists.id', $listId)))
            ->when($request->query('updated_since'), fn ($query, $since) => $query->where('updated_at', '>=', now()->parse((string) $since)))
            ->with(['lists:id,name', 'tags:id,name'])
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $leads->getCollection()->map(fn (Lead $lead): array => $this->present($lead))->all(),
            'meta' => ['page' => $leads->currentPage(), 'per_page' => $leads->perPage(), 'total' => $leads->total(), 'last_page' => $leads->lastPage()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request, $id))]);
    }

    /**
     * Create, or update the lead with the same email (upsert).
     */
    public function store(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $data = $request->validate($this->rules(creating: true));
        $email = Str::lower(trim($data['email']));

        $lead = Lead::query()->where('workspace_id', $workspace->getKey())->where('email', $email)->first();
        $created = $lead === null;

        if ($created && ($reason = app(Entitlements::class)->denies($workspace, 'leads'))) {
            return response()->json(['message' => $reason], 422);
        }

        $lead ??= tap(new Lead, function (Lead $lead) use ($workspace): void {
            $lead->workspace_id = $workspace->getKey();
            $lead->source = 'api';
        });

        $this->fill($lead, [...$data, 'email' => $email]);
        $lead->save();
        $this->syncRelations($lead, $data);

        if ($created) {
            $lead->logActivity(LeadActivityType::Created, 'Added through the API');
        }

        return response()->json(['data' => $this->present($lead->load(['lists:id,name', 'tags:id,name']))], $created ? 201 : 200);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $lead = $this->find($request, $id);
        $data = $request->validate($this->rules(creating: false, ignoreId: $lead->getKey()));

        $this->fill($lead, $data);
        $lead->save();
        $this->syncRelations($lead, $data);

        return response()->json(['data' => $this->present($lead->load(['lists:id,name', 'tags:id,name']))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(null, 204);
    }

    protected function find(Request $request, int $id): Lead
    {
        return Lead::query()
            ->where('workspace_id', $this->workspace($request)->getKey())
            ->with(['lists:id,name', 'tags:id,name'])
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(bool $creating, ?int $ignoreId = null): array
    {
        $fields = collect(Lead::FIELDS)->reject(fn (string $field): bool => $field === 'email')
            ->mapWithKeys(fn (string $field): array => [$field => ['nullable', 'string', 'max:255']])
            ->all();

        return [
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:255'],
            ...$fields,
            'status' => ['sometimes', Rule::enum(LeadStatus::class)],
            'custom_fields' => ['sometimes', 'array', 'max:50'],
            'custom_fields.*' => ['nullable', 'scalar'],
            'list_ids' => ['sometimes', 'array'],
            'list_ids.*' => ['integer'],
            'list_names' => ['sometimes', 'array'],
            'list_names.*' => ['string', 'max:100'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function fill(Lead $lead, array $data): void
    {
        $lead->fill(collect($data)->only([...Lead::FIELDS, 'status'])->all());

        if (array_key_exists('custom_fields', $data)) {
            $lead->custom_fields = [...($lead->custom_fields ?? []), ...$data['custom_fields']];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncRelations(Lead $lead, array $data): void
    {
        $listIds = LeadList::query()
            ->where('workspace_id', $lead->workspace_id)
            ->whereIn('id', $data['list_ids'] ?? [])
            ->pluck('id')
            ->all();

        foreach ($data['list_names'] ?? [] as $name) {
            $list = LeadList::query()->where('workspace_id', $lead->workspace_id)->where('name', trim($name))->first();

            if (! $list) {
                $list = new LeadList(['name' => trim($name)]);
                $list->workspace_id = $lead->workspace_id;
                $list->save();
            }

            $listIds[] = $list->getKey();
        }

        if ($listIds !== []) {
            $lead->lists()->syncWithoutDetaching($listIds);
        }

        if (array_key_exists('tags', $data)) {
            $lead->syncTagNames($data['tags']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Lead $lead): array
    {
        return [
            ...IntegrationEvents::lead($lead),
            'verification_status' => $lead->verification_status?->value,
            'lists' => $lead->relationLoaded('lists') ? $lead->lists->map(fn ($list) => ['id' => $list->id, 'name' => $list->name])->all() : [],
            'tags' => $lead->relationLoaded('tags') ? $lead->tags->pluck('name')->all() : [],
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }
}
