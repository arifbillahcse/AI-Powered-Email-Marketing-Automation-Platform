<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Enums\AiProvider;
use App\Models\AiGeneration;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiResult;
use App\Services\Ai\PromptBuilder;
use App\Services\Ai\TextGeneratorFactory;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use Throwable;

/**
 * Writes one piece of AI content and puts it in the review queue. Rate
 * limits and outages are retried with backoff; anything else marks the
 * generation as failed with a message the user can act on.
 */
class GenerateAiContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Under the 55-second cron window on shared hosting. */
    public int $timeout = 50;

    public function __construct(
        public int $generationId,
        public ?int $requestedBy = null,
        public ?string $batch = null,
    ) {
        $this->onQueue('ai');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(TextGeneratorFactory $generators, PromptBuilder $prompts): void
    {
        $generation = AiGeneration::query()->with(['lead', 'step', 'template'])->find($this->generationId);

        if (! $generation || $generation->status !== AiGenerationStatus::Pending) {
            return;
        }

        // Never pay twice for the same generation if two jobs overlap.
        $lock = Cache::lock("ai-generation:{$generation->getKey()}", $this->timeout + 10);

        if (! $lock->get()) {
            return;
        }

        try {
            if (AiGeneration::query()->whereKey($generation->getKey())->toBase()->value('status') !== AiGenerationStatus::Pending->value) {
                return;
            }

            $this->generate($generation, $generators, $prompts);
        } catch (AiException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $this->markFailed($generation, $exception->getMessage());
        } finally {
            $lock->release();
        }

        $this->notifyWhenDone((int) $generation->workspace_id);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = AiGeneration::query()->find($this->generationId);

        if ($generation?->status === AiGenerationStatus::Pending) {
            $message = $exception instanceof AiException ? $exception->getMessage() : 'The AI request failed. Try regenerating.';
            $this->markFailed($generation, $message);
            $this->notifyWhenDone((int) $generation->workspace_id);
        }
    }

    protected function generate(AiGeneration $generation, TextGeneratorFactory $generators, PromptBuilder $prompts): void
    {
        $setting = AiSetting::for($generation->workspace_id);

        if ($setting->provider === AiProvider::Platform) {
            $allowance = (int) config('outreach.ai.platform_monthly_tokens');

            if (AiUsage::tokensThisMonth($generation->workspace_id, AiProvider::Platform) >= $allowance) {
                throw new AiException('This month\'s included AI credits are used up. Add your own API key in AI settings to continue.');
            }
        }

        $result = $generators->for($setting)->generate(
            $prompts->system($generation->type, $generation->template, $generation->step),
            $prompts->user($generation->lead),
        );

        $this->recordUsage($generation, $setting, $result);

        $output = $result->refused ? '' : $prompts->clean($generation->type, $result->text);

        if ($output === '') {
            throw new AiException('The AI declined to write this one. Edit it by hand or regenerate.');
        }

        $generation->forceFill([
            'output' => $output,
            'edited' => false,
            'status' => AiGenerationStatus::Ready,
            'error' => null,
            'provider' => $setting->provider->value,
            'model' => mb_substr($result->model, 0, 255),
            'generated_at' => now(),
        ])->save();
    }

    protected function recordUsage(AiGeneration $generation, AiSetting $setting, AiResult $result): void
    {
        (new AiUsage)->forceFill([
            'workspace_id' => $generation->workspace_id,
            'ai_generation_id' => $generation->getKey(),
            'provider' => $setting->provider->value,
            'model' => mb_substr($result->model, 0, 255),
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cache_read_tokens' => $result->cacheReadTokens,
            'cache_write_tokens' => $result->cacheWriteTokens,
            'created_at' => now(),
        ])->save();
    }

    protected function markFailed(AiGeneration $generation, string $error): void
    {
        $generation->forceFill([
            'status' => AiGenerationStatus::Failed,
            'error' => mb_substr($error, 0, 1000),
        ])->save();
    }

    /**
     * Tell whoever started the batch once nothing is left to generate.
     */
    protected function notifyWhenDone(int $workspaceId): void
    {
        if (! $this->requestedBy || ! $this->batch) {
            return;
        }

        $pending = AiGeneration::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', AiGenerationStatus::Pending->value)
            ->exists();

        if ($pending || ! Cache::add("ai-batch-done:{$this->batch}", true, now()->addDay())) {
            return;
        }

        $user = User::query()->find($this->requestedBy);

        if (! $user) {
            return;
        }

        $counts = AiGeneration::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', [AiGenerationStatus::Ready->value, AiGenerationStatus::Failed->value])
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $ready = (int) ($counts[AiGenerationStatus::Ready->value] ?? 0);
        $failed = (int) ($counts[AiGenerationStatus::Failed->value] ?? 0);

        Notification::make()
            ->title('AI personalization finished')
            ->body(Number::format($ready).' ready for review'.($failed ? ', '.Number::format($failed).' failed' : '').'. Open AI review to approve them.')
            ->status($failed && ! $ready ? 'danger' : 'success')
            ->sendToDatabase($user);
    }
}
