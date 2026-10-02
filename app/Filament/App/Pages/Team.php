<?php

namespace App\Filament\App\Pages;

use App\Enums\WorkspaceRole;
use App\Filament\App\Concerns\HandlesTeamActions;
use App\Filament\App\Widgets\PendingInvitations;
use App\Models\Membership;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class Team extends Page implements HasTable
{
    use HandlesTeamActions;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.app.pages.team';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Membership::query()
                    ->whereBelongsTo($this->workspace())
                    ->with(['user', 'workspace'])
            )
            ->columns([
                TextColumn::make('user.name')
                    ->label('Name')
                    ->description(fn (Membership $record): string => $record->user->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('role')
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at')
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Membership $record): bool => $this->canManageTeam()
                        && ! $record->isOwner()
                        && $record->user_id !== $this->actor()->getKey())
                    ->fillForm(fn (Membership $record): array => ['role' => $record->role->value])
                    ->schema([
                        Select::make('role')
                            ->options(WorkspaceRole::assignableOptions())
                            ->required(),
                    ])
                    ->action(fn (Membership $record, array $data) => $this->attempt(
                        fn () => $this->teamManager()->changeRole($record, WorkspaceRole::from($data['role']), $this->actor()),
                        'Role updated',
                    )),
                Action::make('remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Membership $record): string => "{$record->user->name} will lose access to this workspace immediately.")
                    ->visible(fn (Membership $record): bool => $this->canManageTeam()
                        && ! $record->isOwner()
                        && $record->user_id !== $this->actor()->getKey())
                    ->action(fn (Membership $record) => $this->attempt(
                        fn () => $this->teamManager()->remove($record, $this->actor()),
                        'Member removed',
                    )),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite member')
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => $this->canManageTeam())
                ->modalDescription('They\'ll get an email with a link to join. Links expire after 7 days.')
                ->modalSubmitActionLabel('Send invitation')
                ->schema([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Select::make('role')
                        ->options(WorkspaceRole::assignableOptions())
                        ->default(WorkspaceRole::Member->value)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $sent = $this->attempt(
                        fn () => $this->teamManager()->invite($this->workspace(), $this->actor(), $data['email'], WorkspaceRole::from($data['role'])),
                        "Invitation sent to {$data['email']}",
                    );

                    if ($sent) {
                        $this->dispatch('invitations-updated');
                    }
                }),
            Action::make('leave')
                ->label('Leave workspace')
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('You\'ll lose access to this workspace until someone invites you again.')
                ->visible(fn (): bool => $this->actor()->roleIn($this->workspace()) !== WorkspaceRole::Owner)
                ->action(function (): void {
                    $left = $this->attempt(
                        fn () => $this->teamManager()->leave($this->workspace(), $this->actor()),
                        'You left the workspace',
                    );

                    if ($left) {
                        $this->redirect(Filament::getCurrentPanel()->getUrl());
                    }
                }),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            PendingInvitations::class,
        ];
    }
}
