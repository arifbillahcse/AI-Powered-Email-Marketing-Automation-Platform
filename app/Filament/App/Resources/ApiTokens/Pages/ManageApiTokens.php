<?php

namespace App\Filament\App\Resources\ApiTokens\Pages;

use App\Filament\App\Resources\ApiTokens\ApiTokenResource;
use App\Models\ApiToken;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ManageApiTokens extends ManageRecords
{
    protected static string $resource = ApiTokenResource::class;

    public function getSubheading(): ?string
    {
        return 'Use a key as "Authorization: Bearer <key>" against '.url('/api/v1').'. Keys only see this workspace.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createKey')
                ->label('New API key')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', ApiToken::class)
                ->schema([
                    TextInput::make('name')->placeholder('Zapier')->required()->maxLength(100),
                    CheckboxList::make('abilities')
                        ->options(['read' => 'Read (list leads, campaigns, replies)', 'write' => 'Write (create and update leads, lists, suppressions; pause campaigns)'])
                        ->default(['read', 'write'])
                        ->required(),
                    Select::make('expires')
                        ->label('Expires')
                        ->options(['never' => 'Never', '30' => 'In 30 days', '90' => 'In 90 days', '365' => 'In a year'])
                        ->default('never')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var Workspace $workspace */
                    $workspace = Filament::getTenant();
                    ['plain' => $plain] = ApiToken::issue(
                        $workspace,
                        $data['name'],
                        array_values(array_intersect(['read', 'write'], $data['abilities'])),
                        Auth::user(),
                        is_numeric($data['expires'] ?? null) ? now()->addDays((int) $data['expires']) : null,
                    );

                    Notification::make()
                        ->title('API key created: copy it now')
                        ->body("{$plain}\n\nIt won't be shown again.")
                        ->persistent()
                        ->success()
                        ->send();
                }),
        ];
    }
}
