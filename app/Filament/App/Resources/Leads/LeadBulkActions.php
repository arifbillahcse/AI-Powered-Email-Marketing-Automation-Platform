<?php

namespace App\Filament\App\Resources\Leads;

use App\Enums\LeadStatus;
use App\Enums\SuppressionReason;
use App\Models\Lead;
use App\Models\LeadList;
use App\Services\Ai\AiRunner;
use App\Services\Ai\LeadEnrichment;
use App\Services\Billing\Entitlements;
use App\Services\Leads\EmailVerification;
use App\Services\Leads\LeadBulkOperations;
use App\Services\Leads\SuppressionList;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

/**
 * Bulk actions that work on the selection *query*, so they stay fast and
 * memory-safe even when "select all" covers tens of thousands of leads.
 */
class LeadBulkActions
{
    /**
     * @return list<BulkAction>
     */
    public static function all(): array
    {
        return [
            static::addToList(),
            static::removeFromList(),
            static::addTags(),
            static::removeTags(),
            static::setStatus(),
            static::verify(),
            static::enrich(),
            static::suppress(),
            static::delete(),
        ];
    }

    protected static function base(string $name): BulkAction
    {
        return BulkAction::make($name)
            ->fetchSelectedRecords(false)
            ->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => Auth::user()->can('create', Lead::class));
    }

    protected static function workspaceId(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    protected static function done(string $title): void
    {
        Notification::make()->title($title)->success()->send();
    }

    public static function verify(): BulkAction
    {
        $locked = fn (): bool => ! app(Entitlements::class)->allowsModule(static::workspaceId(), 'email_verification');

        return static::base('verifyEmails')
            ->label('Verify emails')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->disabled($locked)
            ->tooltip(fn (): ?string => $locked() ? 'Email verification isn\'t included in your plan.' : null)
            ->requiresConfirmation()
            ->modalDescription('Checks each address in the background (syntax, mail server, disposable and role addresses). You\'ll get a notification with the results.')
            ->action(function (Builder $query): void {
                $count = app(EmailVerification::class)->queue(static::workspaceId(), $query, Auth::user());

                static::done('Verifying '.Number::format($count).' '.str('lead')->plural($count));
            });
    }

    public static function enrich(): BulkAction
    {
        $locked = fn (): bool => ! app(AiRunner::class)->available(static::workspaceId());

        return static::base('enrichFromWebsite')
            ->label('Enrich from website (AI)')
            ->icon(Heroicon::OutlinedSparkles)
            ->disabled($locked)
            ->tooltip(fn (): ?string => $locked() ? 'Set up AI in AI settings first (or it isn\'t in your plan).' : null)
            ->requiresConfirmation()
            ->modalDescription('Reads each lead\'s company website (or their business email\'s domain) and adds {{company_summary}}, {{industry}} and {{personalization_hook}} as custom fields. Uses AI credits; runs in the background.')
            ->action(function (Builder $query): void {
                $count = app(LeadEnrichment::class)->queue(static::workspaceId(), $query, Auth::user());

                static::done('Enriching '.Number::format($count).' '.str('lead')->plural($count));
            });
    }

    public static function addToList(): BulkAction
    {
        return static::base('addToList')
            ->label('Add to list')
            ->icon(Heroicon::OutlinedQueueList)
            ->schema([
                Select::make('list_id')
                    ->label('List')
                    ->options(fn (): array => LeadList::query()->where('workspace_id', static::workspaceId())->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->requiredWithout('new_list'),
                TextInput::make('new_list')
                    ->label('…or create a new list')
                    ->maxLength(100),
            ])
            ->action(function (Builder $query, array $data): void {
                $list = filled($data['new_list'] ?? null)
                    ? static::firstOrCreateList(trim($data['new_list']))
                    : LeadList::query()->where('workspace_id', static::workspaceId())->findOrFail($data['list_id']);

                $count = app(LeadBulkOperations::class)->addToList($query, $list);
                static::done(Number::format($count)." leads added to \"{$list->name}\"");
            });
    }

    public static function removeFromList(): BulkAction
    {
        return static::base('removeFromList')
            ->label('Remove from list')
            ->icon(Heroicon::OutlinedQueueList)
            ->schema([
                Select::make('list_id')
                    ->label('List')
                    ->options(fn (): array => LeadList::query()->where('workspace_id', static::workspaceId())->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Builder $query, array $data): void {
                $list = LeadList::query()->where('workspace_id', static::workspaceId())->findOrFail($data['list_id']);
                $count = app(LeadBulkOperations::class)->removeFromList($query, $list);
                static::done(Number::format($count)." leads removed from \"{$list->name}\"");
            });
    }

    public static function addTags(): BulkAction
    {
        return static::base('addTags')
            ->label('Add tags')
            ->icon(Heroicon::OutlinedTag)
            ->schema([
                TagsInput::make('tags')->required(),
            ])
            ->action(function (Builder $query, array $data): void {
                app(LeadBulkOperations::class)->addTags($query, static::workspaceId(), $data['tags']);
                static::done('Tags added');
            });
    }

    public static function removeTags(): BulkAction
    {
        return static::base('removeTags')
            ->label('Remove tags')
            ->icon(Heroicon::OutlinedTag)
            ->schema([
                TagsInput::make('tags')->required(),
            ])
            ->action(function (Builder $query, array $data): void {
                app(LeadBulkOperations::class)->removeTags($query, static::workspaceId(), $data['tags']);
                static::done('Tags removed');
            });
    }

    public static function setStatus(): BulkAction
    {
        return static::base('setStatus')
            ->label('Change status')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->schema([
                Select::make('status')->options(LeadStatus::class)->required(),
            ])
            ->action(function (Builder $query, array $data): void {
                $status = $data['status'] instanceof LeadStatus ? $data['status'] : LeadStatus::from($data['status']);

                // Model events log each change on the timeline.
                (clone $query)->where('leads.workspace_id', static::workspaceId())
                    ->where('status', '!=', $status->value)
                    ->chunkById(500, fn ($leads) => $leads->each->update(['status' => $status]), 'leads.id', 'id');

                static::done("Status changed to {$status->getLabel()}");
            });
    }

    public static function suppress(): BulkAction
    {
        return static::base('suppress')
            ->label('Add to suppression list')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('These people will never be emailed by this workspace again.')
            ->action(function (Builder $query): void {
                $suppressions = app(SuppressionList::class);
                $count = 0;

                (clone $query)->where('leads.workspace_id', static::workspaceId())
                    ->select(['leads.id', 'leads.email'])
                    ->chunkById(500, function ($leads) use ($suppressions, &$count): void {
                        foreach ($leads as $lead) {
                            $suppressions->add(static::workspaceId(), $lead->email, SuppressionReason::Manual, Auth::user());
                            $count++;
                        }
                    }, 'leads.id', 'id');

                static::done(Number::format($count).' leads suppressed');
            });
    }

    public static function delete(): BulkAction
    {
        return static::base('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Deletes the leads with their list memberships, tags and history. Suppressions are kept.')
            ->action(function (Builder $query): void {
                $count = 0;

                (clone $query)->where('leads.workspace_id', static::workspaceId())
                    ->select('leads.id')
                    ->chunkById(1000, function ($leads) use (&$count): void {
                        $count += Lead::query()->whereKey($leads->pluck('id'))->delete();
                    }, 'leads.id', 'id');

                static::done(Number::format($count).' leads deleted');
            });
    }

    protected static function firstOrCreateList(string $name): LeadList
    {
        $list = LeadList::query()->where('workspace_id', static::workspaceId())->where('name', $name)->first();

        if (! $list) {
            $list = new LeadList(['name' => $name]);
            $list->workspace_id = static::workspaceId();
            $list->save();
        }

        return $list;
    }
}
