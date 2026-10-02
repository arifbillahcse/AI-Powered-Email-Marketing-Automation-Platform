<?php

namespace App\Filament\App\Tenancy;

use App\Support\Timezones;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Owners and admins only (WorkspacePolicy::update).
 */
class WorkspaceSettings extends EditTenantProfile
{
    protected static ?string $slug = 'settings';

    public static function getLabel(): string
    {
        return 'Workspace settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Workspace name')
                            ->required()
                            ->maxLength(100),
                        Select::make('timezone')
                            ->label('Time zone')
                            ->options(Timezones::options())
                            ->searchable()
                            ->required()
                            ->in(array_keys(Timezones::options())),
                    ]),
                Section::make('Mailing address')
                    ->description('Anti-spam laws (CAN-SPAM) require a physical postal address in every campaign email. It is added to your email footers automatically. Campaigns can\'t be sent until it\'s filled in.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Company name')
                            ->maxLength(150)
                            ->columnSpanFull(),
                        TextInput::make('address_line1')
                            ->label('Address line 1')
                            ->maxLength(150),
                        TextInput::make('address_line2')
                            ->label('Address line 2')
                            ->maxLength(150),
                        TextInput::make('city')
                            ->maxLength(100),
                        TextInput::make('state')
                            ->label('State / region')
                            ->maxLength(100),
                        TextInput::make('postal_code')
                            ->label('Postal code')
                            ->maxLength(20),
                        TextInput::make('country')
                            ->helperText('2-letter code, e.g. BD, US, GB.')
                            ->length(2)
                            ->regex('/^[A-Za-z]{2}$/')
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
                    ]),
            ]);
    }
}
