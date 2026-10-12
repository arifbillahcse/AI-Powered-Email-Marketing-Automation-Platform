<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Concerns\SuperAdminOnly;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\Admin\Impersonator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use UnitEnum;

class UserResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'email';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('memberships'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->description(fn (User $record): string => $record->email)->searchable(['name', 'email'])->sortable(),
                TextColumn::make('memberships_count')->label('Workspaces'),
                IconColumn::make('email_verified_at')->label('Verified')->boolean()->state(fn (User $record): bool => $record->email_verified_at !== null),
                IconColumn::make('is_super_admin')->label('Super admin')->boolean(),
                TextColumn::make('suspended_at')->label('Suspended')->since()->color('danger')->placeholder('—'),
                TextColumn::make('created_at')->label('Signed up')->since()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('suspended')->queries(
                    true: fn (Builder $query) => $query->whereNotNull('suspended_at'),
                    false: fn (Builder $query) => $query->whereNull('suspended_at'),
                ),
                TernaryFilter::make('is_super_admin')->label('Super admins'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('impersonate')
                        ->label('Log in as')
                        ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
                        ->visible(fn (User $record): bool => ! $record->is_super_admin && $record->suspended_at === null)
                        ->requiresConfirmation()
                        ->modalDescription(fn (User $record): string => "You'll see the app as {$record->email}. A bar at the top takes you back. This is logged.")
                        ->action(function (User $record, Action $action): void {
                            try {
                                app(Impersonator::class)->start(Auth::user(), $record);
                            } catch (InvalidArgumentException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            $action->redirect(url('/app'));
                        }),
                    Action::make('suspend')
                        ->label('Suspend')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (User $record): bool => $record->suspended_at === null && ! $record->is_super_admin)
                        ->requiresConfirmation()
                        ->modalDescription('They can no longer sign in. Their workspaces keep running for the other members.')
                        ->action(function (User $record): void {
                            $record->forceFill(['suspended_at' => now()])->save();
                            Notification::make()->title("{$record->email} suspended")->success()->send();
                        }),
                    Action::make('unsuspend')
                        ->label('Unsuspend')
                        ->icon(Heroicon::OutlinedLockOpen)
                        ->color('success')
                        ->visible(fn (User $record): bool => $record->suspended_at !== null)
                        ->action(function (User $record): void {
                            $record->forceFill(['suspended_at' => null])->save();
                            Notification::make()->title("{$record->email} can sign in again")->success()->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }
}
