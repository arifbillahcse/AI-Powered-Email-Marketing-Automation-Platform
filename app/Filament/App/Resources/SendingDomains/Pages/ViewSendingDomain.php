<?php

namespace App\Filament\App\Resources\SendingDomains\Pages;

use App\Filament\App\Resources\SendingDomains\SendingDomainActions;
use App\Filament\App\Resources\SendingDomains\SendingDomainResource;
use App\Models\SendingDomain;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSendingDomain extends ViewRecord
{
    protected static string $resource = SendingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SendingDomainActions::checkNow(),
            Action::make('setDkimSelector')
                ->label('Set DKIM selector')
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->authorize('update')
                ->fillForm(fn (SendingDomain $record): array => ['dkim_selector' => $record->dkim_selector])
                ->schema([
                    TextInput::make('dkim_selector')
                        ->label('DKIM selector')
                        ->helperText('Leave blank to auto-detect common selectors.')
                        ->regex('/^[a-z0-9._-]+$/i')
                        ->maxLength(63),
                ])
                ->action(function (SendingDomain $record, array $data): void {
                    $record->update(['dkim_selector' => $data['dkim_selector'] ?: null]);

                    Notification::make()->title('DKIM selector saved. Click "Check now" to re-check.')->success()->send();
                }),
        ];
    }
}
