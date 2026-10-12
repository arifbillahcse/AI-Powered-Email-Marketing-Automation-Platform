<?php

namespace App\Jobs;

use App\Models\AiSetting;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Services\Ai\AiException;
use App\Services\Ai\ReplyIntelligence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Classifies a new reply and, when it deserves an answer, drafts one.
 * Rate limits are retried; any other AI failure leaves the reply as it is.
 */
class ProcessInboundReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 50;

    public function __construct(
        public int $inboxMessageId,
    ) {
        $this->onQueue('ai');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ReplyIntelligence $intelligence): void
    {
        $message = InboxMessage::query()->find($this->inboxMessageId);

        if (! $message || $message->ai_processed_at !== null) {
            return;
        }

        try {
            $category = $intelligence->classify($message);

            if ($category->wantsReply() && AiSetting::for($message->workspace_id)->auto_draft_replies) {
                $intelligence->draft(InboxThread::query()->findOrFail($message->inbox_thread_id));
            }
        } catch (AiException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $message->forceFill(['ai_processed_at' => now()])->save();
            report($exception);
        }
    }
}
