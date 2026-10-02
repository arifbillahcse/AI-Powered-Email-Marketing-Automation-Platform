<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Concerns\HandlesTeamActions;
use App\Models\WorkspaceInvitation;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\On;

/**
 * Shown on the Team page only (not discovered onto the dashboard).
 */
class PendingInvitations extends TableWidget
{
    use HandlesTeamActions;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (new static)->canManageTeam();
    }

    #[On('invitations-updated')]
    public function refreshInvitations(): void
    {
        // Re-render with the latest invitations.
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Pending invitations')
            ->query(
                WorkspaceInvitation::query()
                    ->whereBelongsTo($this->workspace())
                    ->pending()
                    ->with(['workspace', 'inviter'])
            )
            ->columns([
                TextColumn::make('email'),
                TextColumn::make('role')->badge(),
                TextColumn::make('inviter.name')->label('Invited by'),
                TextColumn::make('expires_at')->label('Expires')->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No pending invitations')
            ->emptyStateDescription('Invite teammates or clients with the "Invite member" button.')
            ->paginated(false)
            ->recordActions([
                Action::make('resend')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(fn (WorkspaceInvitation $record) => $this->attempt(
                        fn () => $this->teamManager()->resend($record, $this->actor()),
                        "Invitation re-sent to {$record->email}",
                    )),
                Action::make('revoke')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The invitation link will stop working.')
                    ->action(fn (WorkspaceInvitation $record) => $this->attempt(
                        fn () => $this->teamManager()->revoke($record, $this->actor()),
                        'Invitation revoked',
                    )),
            ]);
    }
}
