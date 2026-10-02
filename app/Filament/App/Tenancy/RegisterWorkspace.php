<?php

namespace App\Filament\App\Tenancy;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Support\Timezones;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class RegisterWorkspace extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Create workspace';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Workspace name')
                    ->placeholder('Acme Agency')
                    ->helperText('Usually your company or client name. You can change it later.')
                    ->required()
                    ->maxLength(100),
                Select::make('timezone')
                    ->label('Time zone')
                    ->helperText('Used for campaign schedules and reports.')
                    ->options(Timezones::options())
                    ->searchable()
                    ->default('UTC')
                    ->required()
                    ->in(array_keys(Timezones::options())),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Workspace
    {
        $workspace = Workspace::create($data);

        $workspace->addMember(Auth::user(), WorkspaceRole::Owner);

        return $workspace;
    }
}
