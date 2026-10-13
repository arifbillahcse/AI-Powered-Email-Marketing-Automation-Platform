<?php

namespace App\Filament\App\Pages;

use App\Enums\CampaignTaskStatus;
use App\Enums\StepType;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Models\CampaignTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignTasks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Manual steps of multichannel sequences (LinkedIn, calls, other tasks)
 * that are due. Completing or skipping one moves the lead's sequence on.
 */
class Tasks extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 25;

    protected static ?string $title = 'Tasks';

    protected static ?string $slug = 'tasks';

    public static function getNavigationBadge(): ?string
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace) {
            return null;
        }

        $open = CampaignTask::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', CampaignTaskStatus::Open->value)
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('LinkedIn, call and other manual steps of your sequences. The lead\'s sequence continues once the task is done or skipped.')
                ->color('gray'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => CampaignTask::query()
                ->where('workspace_id', $this->workspace()->getKey())
                ->with(['lead', 'campaign', 'completedBy']))
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('type')
                    ->label('Step')
                    ->badge(),
                TextColumn::make('title')
                    ->wrap()
                    ->description(fn (CampaignTask $record): ?string => $record->instructions ? str($record->instructions)->squish()->limit(120)->toString() : null)
                    ->searchable(),
                TextColumn::make('lead.email')
                    ->label('Lead')
                    ->description(fn (CampaignTask $record): string => collect([$record->lead?->fullName(), $record->lead?->company, $record->type === StepType::Call ? $record->lead?->phone : null])->filter()->implode(' · '))
                    ->url(fn (CampaignTask $record): ?string => $record->lead ? LeadResource::getUrl('view', ['record' => $record->lead]) : null)
                    ->searchable(),
                TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->toggleable(),
                TextColumn::make('due_at')
                    ->label('Due')
                    ->since()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (CampaignTask $record): ?string => $record->completedBy ? "by {$record->completedBy->name}" : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CampaignTaskStatus::class)
                    ->default(CampaignTaskStatus::Open->value),
                SelectFilter::make('type')
                    ->label('Step')
                    ->options(collect(StepType::cases())->reject(fn (StepType $type): bool => $type === StepType::Email)
                        ->mapWithKeys(fn (StepType $type): array => [$type->value => $type->getLabel()])->all()),
            ])
            ->recordActions([
                Action::make('openLinkedin')
                    ->label('LinkedIn')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (CampaignTask $record): bool => in_array($record->type, [StepType::LinkedinVisit, StepType::LinkedinConnect, StepType::LinkedinMessage], true)
                        && $this->safeUrl($record->lead?->linkedin_url) !== null)
                    ->url(fn (CampaignTask $record): ?string => $this->safeUrl($record->lead?->linkedin_url))
                    ->openUrlInNewTab(),
                Action::make('notes')
                    ->label('Notes')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->visible(fn (CampaignTask $record): bool => filled($record->instructions))
                    ->modalHeading(fn (CampaignTask $record): string => $record->title)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    // Plain text, escaped by the component: never rendered as HTML.
                    ->schema(fn (CampaignTask $record): array => [Text::make((string) $record->instructions)]),
                Action::make('complete')
                    ->label('Done')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (CampaignTask $record): bool => $record->isOpen() && $this->canWrite())
                    ->action(function (CampaignTask $record): void {
                        app(CampaignTasks::class)->complete($record, Auth::user());
                        Notification::make()->title('Task done')->body('The lead\'s sequence continues.')->success()->send();
                    }),
                Action::make('skip')
                    ->label('Skip')
                    ->icon(Heroicon::OutlinedForward)
                    ->color('gray')
                    ->visible(fn (CampaignTask $record): bool => $record->isOpen() && $this->canWrite())
                    ->requiresConfirmation()
                    ->modalDescription('The sequence continues without this step.')
                    ->action(function (CampaignTask $record): void {
                        app(CampaignTasks::class)->skip($record, Auth::user());
                        Notification::make()->title('Task skipped')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Nothing to do')
            ->emptyStateDescription('Add LinkedIn, call or task steps to a campaign sequence; they show up here when a lead reaches them.');
    }

    protected function safeUrl(?string $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }

    protected function canWrite(): bool
    {
        $user = Auth::user();

        return $user instanceof User && (bool) $user->roleIn($this->workspace())?->canWrite();
    }

    protected function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
