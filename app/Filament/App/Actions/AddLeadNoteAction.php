<?php

namespace App\Filament\App\Actions;

use App\Models\Lead;
use App\Models\LeadNote;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Add note": a quick private note on a lead (what you found on their
 * website, who you spoke to, what to do next). Ctrl/Cmd+Enter saves.
 */
class AddLeadNoteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'addNote';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Add note')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->authorize('update')
            ->modalHeading(fn (Lead $record): string => 'Note on '.($record->fullName() ?: $record->email))
            ->modalDescription('Your team sees it on the lead. It is never sent to the lead.')
            ->modalSubmitActionLabel('Save note')
            ->modalSubmitAction(fn (Action $action): Action => $action->keyBindings(['mod+enter']))
            ->schema([
                Textarea::make('body')
                    ->label('Note')
                    ->placeholder('Visited their site, no careers page. Emailed the contact form on Oct 30. Ask again next week.')
                    ->rows(6)
                    ->autofocus()
                    ->required()
                    ->maxLength(LeadNote::MAX_LENGTH)
                    ->helperText('Ctrl+Enter (⌘+Enter on Mac) saves.'),
            ])
            ->action(function (Lead $record, array $data): void {
                $note = new LeadNote(['body' => trim($data['body'])]);
                $note->workspace_id = $record->workspace_id;
                $note->lead_id = $record->getKey();
                $note->setRelation('lead', $record);
                $note->save();

                Notification::make()->title('Note saved')->success()->send();
            });
    }
}
