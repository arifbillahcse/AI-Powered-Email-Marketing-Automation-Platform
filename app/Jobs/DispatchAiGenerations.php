<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Models\AiGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fans a workspace's pending AI generations out into one job each, a chunk
 * at a time (the next chunk is a new job, so each run stays short).
 */
class DispatchAiGenerations implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 500;

    public function __construct(
        public int $workspaceId,
        public ?int $requestedBy,
        public string $batch,
        public int $afterId = 0,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $ids = AiGeneration::query()
            ->where('workspace_id', $this->workspaceId)
            ->where('status', AiGenerationStatus::Pending->value)
            ->where('id', '>', $this->afterId)
            ->orderBy('id')
            ->limit(self::CHUNK)
            ->pluck('id');

        foreach ($ids as $id) {
            GenerateAiContent::dispatch((int) $id, $this->requestedBy, $this->batch);
        }

        if ($ids->count() === self::CHUNK) {
            static::dispatch($this->workspaceId, $this->requestedBy, $this->batch, (int) $ids->last());
        }
    }
}
