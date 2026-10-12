<?php

namespace App\Services\Ai;

use App\Jobs\EnrichLeads;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Number;

/**
 * Queues website enrichment for a selection of leads and tells the user
 * how many were enriched once the batch is done.
 */
class LeadEnrichment
{
    public const CHUNK = 20;

    /**
     * @param  Builder<Lead>  $leads
     * @return int Leads queued
     */
    public function queue(int $workspaceId, Builder $leads, ?User $by = null): int
    {
        $jobs = [];
        $count = 0;

        (clone $leads)
            ->setEagerLoads([])
            ->where('leads.workspace_id', $workspaceId)
            ->select('leads.id')
            ->reorder()
            ->chunkById(self::CHUNK, function ($chunk) use ($workspaceId, &$jobs, &$count): void {
                $ids = $chunk->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $jobs[] = new EnrichLeads($workspaceId, $ids);
                $count += count($ids);
            }, 'leads.id', 'id');

        if ($jobs === []) {
            return 0;
        }

        $since = now()->subSecond()->toDateTimeString();
        $userId = $by?->getKey();

        Bus::batch($jobs)
            ->name('enrich-leads')
            ->onQueue('ai')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($workspaceId, $userId, $since, $count): void {
                if ($userId && ($user = User::query()->find($userId))) {
                    app(LeadEnrichment::class)->notify($user, $workspaceId, $since, $count);
                }
            })
            ->dispatch();

        return $count;
    }

    public function notify(User $user, int $workspaceId, string $since, int $total): void
    {
        $enriched = LeadActivity::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'enriched')
            ->where('created_at', '>=', $since)
            ->count();

        Notification::make()
            ->title('Website enrichment finished')
            ->body(Number::format($enriched).' of '.Number::format($total).' leads enriched. Use {{company_summary}}, {{industry}} or {{personalization_hook}} in emails and AI prompts. Leads without a readable website were skipped.')
            ->success()
            ->sendToDatabase($user);
    }
}
