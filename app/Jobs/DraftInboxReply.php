<?php

namespace App\Jobs;

use App\Filament\App\Resources\InboxThreads\InboxThreadResource;
use App\Models\InboxThread;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiException;
use App\Services\Ai\ReplyIntelligence;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Write AI draft" in the Unibox: drafts an answer and tells the user.
 */
class DraftInboxReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(
        public int $threadId,
        public int $userId,
    ) {
        $this->onQueue('ai');
    }

    public function handle(ReplyIntelligence $intelligence): void
    {
        $thread = InboxThread::query()->find($this->threadId);
        $user = User::query()->find($this->userId);

        if (! $thread || ! $user) {
            return;
        }

        try {
            $intelligence->draft($thread);
        } catch (AiException $exception) {
            Notification::make()->title('No AI draft')->body($exception->getMessage())->danger()->sendToDatabase($user);

            return;
        }

        $notification = Notification::make()
            ->title('AI draft ready')
            ->body('Open the conversation and click Reply: the draft is filled in for you to edit.')
            ->success();

        try {
            $workspace = Workspace::query()->find($thread->workspace_id);
            $notification->actions([Action::make('open')->label('Open conversation')->url(InboxThreadResource::getUrl('view', ['record' => $thread], panel: 'app', tenant: $workspace))]);
        } catch (Throwable) {
            // The link is a convenience.
        }

        $notification->sendToDatabase($user);
    }
}
