<?php

namespace App\Jobs;

use App\Filament\App\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiException;
use App\Services\Ai\CampaignCopilot;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class WriteCampaignSequence implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    /**
     * @param  array{offer: string, audience?: ?string, call_to_action?: ?string, emails: int, tone?: ?string}  $brief
     */
    public function __construct(
        public int $campaignId,
        public array $brief,
        public int $userId,
    ) {
        $this->onQueue('ai');
    }

    public function handle(CampaignCopilot $copilot): void
    {
        $campaign = Campaign::query()->find($this->campaignId);
        $user = User::query()->find($this->userId);

        if (! $campaign || ! $user) {
            return;
        }

        try {
            $count = $copilot->write($campaign, $this->brief);
        } catch (AiException|InvalidArgumentException $exception) {
            Notification::make()->title('The copilot couldn\'t write the sequence')->body($exception->getMessage())->danger()->sendToDatabase($user);

            return;
        }

        $notification = Notification::make()
            ->title("Sequence written: {$count} ".str('email')->plural($count))
            ->body("\"{$campaign->name}\" has a new sequence. Review and edit it before launching.")
            ->success();

        try {
            $workspace = Workspace::query()->find($campaign->workspace_id);
            $notification->actions([Action::make('open')->label('Open campaign')->url(CampaignResource::getUrl('edit', ['record' => $campaign], panel: 'app', tenant: $workspace))]);
        } catch (Throwable) {
            // The link is a convenience.
        }

        $notification->sendToDatabase($user);
    }
}
