<?php

namespace App\Filament\App\Resources\InboxThreads;

use App\Enums\LeadStatus;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\InboxThreads\Pages\ListInboxThreads;
use App\Filament\App\Resources\InboxThreads\Pages\ViewInboxThread;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\InboxThread;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * The Unibox: replies from every mailbox in one place, plus the one-off
 * emails sent to single leads.
 */
class InboxThreadResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = InboxThread::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Unibox';

    protected static ?string $modelLabel = 'conversation';

    protected static ?string $slug = 'unibox';

    public static function getNavigationBadge(): ?string
    {
        $unread = static::getEloquentQuery()->where('unread', true)->where('auto_reply', false)->count();

        return $unread > 0 ? Number::format($unread) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Unread replies';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lead', 'campaign', 'emailAccount']))
            ->poll('30s')
            ->columns([
                TextColumn::make('lead.email')
                    ->label('From')
                    ->weight(fn (InboxThread $record): FontWeight => $record->unread ? FontWeight::Bold : FontWeight::Medium)
                    ->description(fn (InboxThread $record): ?string => collect([$record->lead->fullName(), $record->lead->company])->filter()->implode(' · ') ?: null)
                    ->searchable(),
                TextColumn::make('subject')
                    ->label('Conversation')
                    ->weight(fn (InboxThread $record): ?FontWeight => $record->unread ? FontWeight::Bold : null)
                    ->description(fn (InboxThread $record): ?string => $record->snippet)
                    ->placeholder('(no subject)')
                    ->wrap()
                    ->searchable(['subject', 'snippet']),
                TextColumn::make('auto_reply')
                    ->label('')
                    ->badge()
                    ->color('warning')
                    ->state(fn (InboxThread $record): ?string => $record->auto_reply ? 'Out of office' : null),
                TextColumn::make('lead.status')
                    ->label('Label')
                    ->badge(),
                TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->placeholder('One-off email')
                    ->toggleable(),
                TextColumn::make('emailAccount.email')
                    ->label('Mailbox')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_message_at')
                    ->label('Last message')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                TernaryFilter::make('unread')
                    ->label('Read status')
                    ->trueLabel('Unread')
                    ->falseLabel('Read'),
                SelectFilter::make('label')
                    ->label('Label')
                    ->options(LeadStatus::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('lead', fn (Builder $lead) => $lead->where('status', $data['value']))
                        : $query),
                SelectFilter::make('campaign_id')
                    ->label('Campaign')
                    ->options(fn (): array => Campaign::query()->where('workspace_id', static::workspaceId())->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('email_account_id')
                    ->label('Mailbox')
                    ->options(fn (): array => EmailAccount::query()->where('workspace_id', static::workspaceId())->orderBy('email')->pluck('email', 'id')->all()),
                TernaryFilter::make('auto_reply')
                    ->label('Out-of-office')
                    ->placeholder('Show all')
                    ->trueLabel('Only out-of-office')
                    ->falseLabel('Hide out-of-office')
                    ->default(false),
            ])
            ->recordUrl(fn (InboxThread $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateIcon(Heroicon::OutlinedInboxStack)
            ->emptyStateHeading('No replies yet')
            ->emptyStateDescription('Replies to your campaigns from every connected mailbox show up here. Mailboxes are checked every few minutes.')
            ->toolbarActions([
                BulkActionGroup::make([
                    static::markBulk('markRead', 'Mark as read', Heroicon::OutlinedEnvelopeOpen, false),
                    static::markBulk('markUnread', 'Mark as unread', Heroicon::OutlinedEnvelope, true),
                ]),
            ]);
    }

    protected static function markBulk(string $name, string $label, Heroicon $icon, bool $unread): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->fetchSelectedRecords(false)
            ->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => (bool) Auth::user()->roleIn(static::workspaceId())?->canWrite())
            ->action(function (Builder $query) use ($unread): void {
                $count = 0;

                (clone $query)
                    ->setEagerLoads([])
                    ->where('inbox_threads.workspace_id', static::workspaceId())
                    ->select('inbox_threads.id')
                    ->reorder()
                    ->chunkById(1000, function ($chunk) use ($unread, &$count): void {
                        $count += InboxThread::query()->whereIn('id', $chunk->pluck('id'))->update(['unread' => $unread]);
                    }, 'inbox_threads.id', 'id');

                Notification::make()->title(Number::format($count).' '.($count === 1 ? 'conversation' : 'conversations').' updated')->success()->send();
            });
    }

    protected static function workspaceId(): int
    {
        return (int) Filament::getTenant()?->getKey();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInboxThreads::route('/'),
            'view' => ViewInboxThread::route('/{record}'),
        ];
    }
}
