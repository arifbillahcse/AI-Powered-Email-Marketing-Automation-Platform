<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Ai\AiException;
use App\Services\Ai\WebsiteEnricher;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Enriches a chunk of leads from their websites. Stops before the cron
 * worker's time limit and queues the rest in the same batch.
 */
class EnrichLeads implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 55;

    /**
     * @param  list<int>  $leadIds
     */
    public function __construct(
        public int $workspaceId,
        public array $leadIds,
    ) {
        $this->onQueue('ai');
    }

    public function handle(WebsiteEnricher $enricher): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $started = microtime(true);
        $remaining = $this->leadIds;

        while ($remaining !== []) {
            // One lead can take ~10s (fetch) + ~10s (AI): leave room.
            if (microtime(true) - $started > 25) {
                $next = new self($this->workspaceId, $remaining);
                $this->batch() ? $this->batch()->add([$next]) : dispatch($next);

                return;
            }

            $lead = Lead::query()->where('workspace_id', $this->workspaceId)->find(array_shift($remaining));

            if (! $lead) {
                continue;
            }

            try {
                $enricher->enrich($lead);
            } catch (AiException $exception) {
                if ($exception->retryable) {
                    // Rate limited: try the rest later instead of failing them all.
                    $next = (new self($this->workspaceId, [$lead->getKey(), ...$remaining]))->delay(now()->addMinute());
                    $this->batch() ? $this->batch()->add([$next]) : dispatch($next);

                    return;
                }

                report($exception);
            }
        }
    }
}
