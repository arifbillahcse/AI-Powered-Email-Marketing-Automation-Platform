<?php

namespace App\Services\Leads;

use App\Enums\LeadActivityType;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * List and tag changes for any number of leads, done by query in chunks so
 * "select all 50,000" never loads every lead into memory.
 */
class LeadBulkOperations
{
    public const CHUNK = 1000;

    /**
     * @param  Builder<Lead>  $leads
     */
    public function addToList(Builder $leads, LeadList $list): int
    {
        return $this->eachChunk($leads, $list->workspace_id, function (array $ids) use ($list): int {
            $now = now();

            $inserted = DB::table('lead_list_lead')->insertOrIgnore(array_map(
                fn (int $id): array => ['lead_list_id' => $list->getKey(), 'lead_id' => $id, 'created_at' => $now],
                $ids,
            ));

            $this->logMany($ids, $list->workspace_id, LeadActivityType::AddedToList, "Added to list \"{$list->name}\"");

            return $inserted;
        });
    }

    /**
     * @param  Builder<Lead>  $leads
     */
    public function removeFromList(Builder $leads, LeadList $list): int
    {
        return $this->eachChunk($leads, $list->workspace_id, function (array $ids) use ($list): int {
            $deleted = DB::table('lead_list_lead')
                ->where('lead_list_id', $list->getKey())
                ->whereIn('lead_id', $ids)
                ->delete();

            $this->logMany($ids, $list->workspace_id, LeadActivityType::RemovedFromList, "Removed from list \"{$list->name}\"");

            return $deleted;
        });
    }

    /**
     * @param  Builder<Lead>  $leads
     * @param  array<string>  $names
     */
    public function addTags(Builder $leads, int $workspaceId, array $names): int
    {
        $tagIds = Tag::idsForNames($workspaceId, $names);

        if ($tagIds === []) {
            return 0;
        }

        $label = Tag::whereKey($tagIds)->pluck('name')->implode(', ');

        return $this->eachChunk($leads, $workspaceId, function (array $ids) use ($tagIds, $workspaceId, $label): int {
            $rows = [];

            foreach ($ids as $leadId) {
                foreach ($tagIds as $tagId) {
                    $rows[] = ['tag_id' => $tagId, 'lead_id' => $leadId];
                }
            }

            $inserted = DB::table('lead_tag')->insertOrIgnore($rows);
            $this->logMany($ids, $workspaceId, LeadActivityType::Tagged, "Tagged {$label}");

            return $inserted;
        });
    }

    /**
     * @param  Builder<Lead>  $leads
     * @param  array<string>  $names
     */
    public function removeTags(Builder $leads, int $workspaceId, array $names): int
    {
        $names = array_map(Tag::normalizeName(...), $names);
        $tagIds = Tag::query()->where('workspace_id', $workspaceId)->whereIn('name', $names)->pluck('id')->all();

        if ($tagIds === []) {
            return 0;
        }

        return $this->eachChunk($leads, $workspaceId, function (array $ids) use ($tagIds, $workspaceId, $names): int {
            $deleted = DB::table('lead_tag')->whereIn('tag_id', $tagIds)->whereIn('lead_id', $ids)->delete();
            $this->logMany($ids, $workspaceId, LeadActivityType::Untagged, 'Removed tag '.implode(', ', $names));

            return $deleted;
        });
    }

    /**
     * Run $callback for each chunk of lead IDs (always limited to the workspace).
     *
     * @param  Builder<Lead>  $leads
     * @param  callable(list<int>): int  $callback
     */
    protected function eachChunk(Builder $leads, int $workspaceId, callable $callback): int
    {
        $affected = 0;

        (clone $leads)
            ->where('leads.workspace_id', $workspaceId)
            ->select('leads.id')
            ->reorder()
            ->chunkById(self::CHUNK, function ($chunk) use ($callback, &$affected): void {
                $affected += $callback($chunk->pluck('id')->map(fn ($id): int => (int) $id)->all());
            }, 'leads.id', 'id');

        return $affected;
    }

    /**
     * @param  list<int>  $leadIds
     */
    protected function logMany(array $leadIds, int $workspaceId, LeadActivityType $type, string $description): void
    {
        $now = now();
        $userId = auth()->id();

        DB::table('lead_activities')->insert(array_map(fn (int $leadId): array => [
            'workspace_id' => $workspaceId,
            'lead_id' => $leadId,
            'user_id' => $userId,
            'type' => $type->value,
            'description' => mb_substr($description, 0, 250),
            'properties' => null,
            'created_at' => $now,
        ], $leadIds));
    }
}
