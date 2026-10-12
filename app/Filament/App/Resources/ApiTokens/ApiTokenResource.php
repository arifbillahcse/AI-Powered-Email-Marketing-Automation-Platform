<?php

namespace App\Filament\App\Resources\ApiTokens;

use App\Filament\App\Resources\ApiTokens\Pages\ManageApiTokens;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Models\ApiToken;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ApiTokenResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = ApiToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'API keys';

    protected static ?string $modelLabel = 'API key';

    protected static ?string $slug = 'api-keys';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('prefix')->label('Key')->formatStateUsing(fn (string $state): string => "{$state}…")->fontFamily('mono'),
                TextColumn::make('abilities')->badge()->color('gray'),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                TextColumn::make('expires_at')->label('Expires')->date()->placeholder('Never'),
                TextColumn::make('created_at')->label('Created')->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No API keys yet')
            ->emptyStateDescription('Create a key to use the REST API (/api/v1), e.g. from Zapier, Make or your own app.')
            ->recordActions([
                DeleteAction::make()
                    ->label('Revoke')
                    ->modalDescription('Apps using this key stop working right away.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageApiTokens::route('/'),
        ];
    }
}
