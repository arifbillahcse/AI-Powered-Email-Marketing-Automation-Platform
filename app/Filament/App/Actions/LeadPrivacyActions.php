<?php

namespace App\Filament\App\Actions;

use App\Enums\LeadActivityType;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Services\Compliance\LeadDataExporter;
use App\Services\Compliance\LeadEraser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GDPR requests for one lead: a download of everything stored about them
 * (access / portability) and erasure, which keeps only the address on the
 * suppression list.
 */
class LeadPrivacyActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            static::export(),
            static::erase(),
        ])
            ->label('Privacy')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->button();
    }

    public static function export(): Action
    {
        return Action::make('exportPersonalData')
            ->label('Download personal data')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->authorize('update')
            ->action(function (Lead $record): StreamedResponse {
                $json = app(LeadDataExporter::class)->toJson($record);
                $record->logActivity(LeadActivityType::Updated, 'Personal data exported (GDPR request)');

                return response()->streamDownload(function () use ($json): void {
                    echo $json;
                }, 'lead-'.Str::slug(Str::before($record->email, '@')).'-'.$record->getKey().'-data.json', ['Content-Type' => 'application/json']);
            });
    }

    public static function erase(): Action
    {
        return Action::make('erasePersonalData')
            ->label('Erase personal data')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize('delete')
            ->visible(fn (Lead $record): bool => (bool) Auth::user()?->roleIn($record->workspace_id)?->canManageTeam())
            ->requiresConfirmation()
            ->modalHeading(fn (Lead $record): string => "Erase {$record->email}?")
            ->modalDescription('Deletes the lead and everything about them: profile, timeline, campaign progress, emails sent, replies and AI content. Only the email address is kept, on the suppression list, so they are never emailed again. Their emails stop counting in analytics. This can\'t be undone.')
            ->modalSubmitActionLabel('Erase permanently')
            ->action(function (Lead $record, Action $action): void {
                $email = $record->email;
                app(LeadEraser::class)->erase($record, Auth::user());

                Notification::make()->title("{$email} erased")->body('Kept on the suppression list only.')->success()->send();
                $action->redirect(LeadResource::getUrl('index'));
            });
    }
}
