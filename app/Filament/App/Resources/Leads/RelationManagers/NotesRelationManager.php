<?php

namespace App\Filament\App\Resources\Leads\RelationManagers;

use App\Models\Lead;
use App\Models\LeadNote;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The lead's notes, newest first. Everyone in the workspace reads them;
 * you change your own (owners and admins can remove any).
 */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Notes';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->notes()->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Notes are added on the lead's page, which is a "view" page; the
     * policy decides who may write.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')
                ->label('Note')
                ->rows(6)
                ->required()
                ->maxLength(LeadNote::MAX_LENGTH)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('author'))
            ->columns([
                TextColumn::make('body')
                    ->label('Note')
                    ->wrap()
                    ->extraAttributes(['class' => 'whitespace-pre-line'])
                    ->description(fn (LeadNote $record): string => ($record->author?->name ?? 'Former team member').' · '.$record->created_at?->diffForHumans()
                        .($record->updated_at?->gt($record->created_at?->addMinute()) ? ' (edited)' : '')),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Add note')
                    ->modalHeading('Add note')
                    ->modalSubmitActionLabel('Save note')
                    ->using(function (array $data, RelationManager $livewire): LeadNote {
                        /** @var Lead $lead */
                        $lead = $livewire->getOwnerRecord();

                        $note = new LeadNote(['body' => trim($data['body'])]);
                        $note->workspace_id = $lead->workspace_id;
                        $note->lead_id = $lead->getKey();
                        $note->setRelation('lead', $lead);
                        $note->save();

                        return $note;
                    }),
            ])
            ->recordActions([
                EditAction::make()->modalHeading('Edit note'),
                DeleteAction::make()->modalHeading('Delete note'),
            ])
            ->emptyStateHeading('No notes yet')
            ->emptyStateDescription('Write down what you found on their website, who you contacted and what to do next.');
    }
}
