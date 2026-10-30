<?php

namespace App\Filament\App\Actions;

use App\Models\Lead;
use App\Services\Leads\FollowUps;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Clears a lead's follow-up once you've followed up.
 */
class LeadFollowUpDoneAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'followUpDone';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Follow-up done')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('update')
            ->visible(fn (Lead $record): bool => $record->follow_up_on !== null)
            ->action(function (Lead $record): void {
                app(FollowUps::class)->done($record);

                Notification::make()->title('Follow-up cleared')->success()->send();
            });
    }
}
