<?php

namespace App\Filament\App\Resources\AiGenerations;

use App\Enums\AiContentType;
use App\Enums\AiGenerationStatus;
use App\Filament\App\Resources\AiGenerations\Pages\ListAiGenerations;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Models\AiGeneration;
use App\Models\Campaign;
use App\Services\Ai\AiGenerationService;
use App\Services\Ai\PromptBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * The AI review queue: read what the AI wrote for each lead, then approve,
 * edit, reject or regenerate it. Only approved content is sent.
 */
class AiGenerationResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = AiGeneration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'AI review';

    protected static ?string $modelLabel = 'AI content';

    protected static ?string $pluralModelLabel = 'AI content';

    protected static ?string $slug = 'ai-review';

    public static function getNavigationBadge(): ?string
    {
        $ready = static::getEloquentQuery()->where('status', AiGenerationStatus::Ready->value)->count();

        return $ready > 0 ? Number::format($ready) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for review';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lead', 'campaign', 'step']))
            ->poll(fn (): ?string => static::getEloquentQuery()->where('status', AiGenerationStatus::Pending->value)->exists() ? '5s' : null)
            ->columns([
                TextColumn::make('lead.email')
                    ->label('Lead')
                    ->description(fn (AiGeneration $record): ?string => collect([$record->lead->fullName(), $record->lead->company])->filter()->implode(' · ') ?: null)
                    ->searchable(),
                TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->description(fn (AiGeneration $record): string => "Email {$record->step->position} · {$record->type->getLabel()}"),
                TextColumn::make('output')
                    ->label('Content')
                    ->wrap()
                    ->limit(300)
                    ->placeholder(fn (AiGeneration $record): string => $record->status === AiGenerationStatus::Pending ? 'Writing…' : '—')
                    ->description(fn (AiGeneration $record): ?string => $record->status === AiGenerationStatus::Failed ? $record->error : null)
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (AiGeneration $record): ?string => $record->edited ? 'Edited' : null),
                TextColumn::make('generated_at')
                    ->label('Written')
                    ->since()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('model')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status')
                    ->options(AiGenerationStatus::class)
                    ->default(AiGenerationStatus::Ready->value),
                SelectFilter::make('campaign_id')
                    ->label('Campaign')
                    ->options(fn (): array => Campaign::query()
                        ->where('workspace_id', Filament::getTenant()?->getKey())
                        ->where('is_template', false)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                SelectFilter::make('type')->options(AiContentType::class),
            ])
            ->emptyStateIcon(Heroicon::OutlinedSparkles)
            ->emptyStateHeading('Nothing to review')
            ->emptyStateDescription('Open a campaign and use "AI personalize" to write first lines, subjects or whole emails for every lead. They appear here for review.')
            ->recordActions([
                Action::make('approve')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->authorize('update')
                    ->visible(fn (AiGeneration $record): bool => filled($record->output)
                        && in_array($record->status, [AiGenerationStatus::Ready, AiGenerationStatus::Rejected], true))
                    ->action(fn (AiGeneration $record) => app(AiGenerationService::class)->approve($record, Auth::user())),
                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->authorize('update')
                    ->visible(fn (AiGeneration $record): bool => $record->status !== AiGenerationStatus::Pending)
                    ->modalHeading(fn (AiGeneration $record): string => "Edit {$record->type->getLabel()} for {$record->lead->email}")
                    ->modalDescription('Saving approves it.')
                    ->modalSubmitActionLabel('Save and approve')
                    ->fillForm(fn (AiGeneration $record): array => ['output' => $record->output])
                    ->schema([
                        Textarea::make('output')
                            ->label('Content')
                            ->required()
                            ->rows(fn (AiGeneration $record): int => $record->type === AiContentType::EmailBody ? 12 : 3)
                            ->maxLength(fn (AiGeneration $record): int => PromptBuilder::MAX_LENGTH[$record->type->value]),
                    ])
                    ->action(function (AiGeneration $record, array $data): void {
                        app(AiGenerationService::class)->edit($record, $data['output'], Auth::user());
                        Notification::make()->title('Saved and approved')->success()->send();
                    }),
                Action::make('reject')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('warning')
                    ->authorize('update')
                    ->visible(fn (AiGeneration $record): bool => in_array($record->status, [AiGenerationStatus::Ready, AiGenerationStatus::Approved], true))
                    ->action(fn (AiGeneration $record) => app(AiGenerationService::class)->reject($record)),
                Action::make('regenerate')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->authorize('update')
                    ->visible(fn (AiGeneration $record): bool => $record->status !== AiGenerationStatus::Pending)
                    ->action(function (AiGeneration $record): void {
                        app(AiGenerationService::class)->regenerate($record, Auth::user());
                        Notification::make()->title('Writing a new version…')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::bulk('approveSelected', 'Approve', Heroicon::OutlinedCheck, 'success',
                        fn (Builder $query): int => app(AiGenerationService::class)->approveMany($query, static::workspaceId(), Auth::user()),
                        'approved'),
                    static::bulk('rejectSelected', 'Reject', Heroicon::OutlinedXMark, 'warning',
                        fn (Builder $query): int => app(AiGenerationService::class)->rejectMany($query, static::workspaceId()),
                        'rejected'),
                    static::bulk('regenerateSelected', 'Regenerate', Heroicon::OutlinedArrowPath, 'gray',
                        fn (Builder $query): int => app(AiGenerationService::class)->regenerateMany($query, static::workspaceId(), Auth::user()),
                        'queued for a new version'),
                ]),
            ]);
    }

    /**
     * @param  callable(Builder<AiGeneration>): int  $operation
     */
    protected static function bulk(string $name, string $label, Heroicon $icon, string $color, callable $operation, string $done): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->fetchSelectedRecords(false)
            ->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => (bool) Auth::user()->roleIn(static::workspaceId())?->canWrite())
            ->action(function (Builder $query) use ($operation, $done): void {
                $count = $operation($query);
                Notification::make()->title(Number::format($count).' '.($count === 1 ? 'item' : 'items')." {$done}")->success()->send();
            });
    }

    protected static function workspaceId(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiGenerations::route('/'),
        ];
    }
}
