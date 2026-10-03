<?php

namespace App\Filament\App\Resources\Suppressions\Pages;

use App\Enums\SuppressionReason;
use App\Filament\App\Resources\Suppressions\SuppressionResource;
use App\Models\Suppression;
use App\Services\Leads\SuppressionList;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ManageSuppressions extends ManageRecords
{
    protected static string $resource = SuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Add to suppression list')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => Auth::user()->can('create', Suppression::class))
                ->modalDescription('One per line. Use a full address (jane@acme.com) or a domain (acme.com) to block everyone there.')
                ->schema([
                    Textarea::make('entries')
                        ->label('Emails or domains')
                        ->rows(8)
                        ->required()
                        ->maxLength(100_000),
                ])
                ->action(function (array $data): void {
                    $result = app(SuppressionList::class)->addMany(
                        Filament::getTenant()->getKey(),
                        $data['entries'],
                        SuppressionReason::Manual,
                        Auth::user(),
                    );

                    $notification = Notification::make()->title("{$result['added']} added to the suppression list");

                    if ($result['invalid'] !== []) {
                        $notification
                            ->body('Skipped invalid entries: '.Str::limit(implode(', ', $result['invalid']), 300))
                            ->warning();
                    } else {
                        $notification->success();
                    }

                    $notification->send();
                }),
        ];
    }
}
