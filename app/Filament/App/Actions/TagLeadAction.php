<?php

namespace App\Filament\App\Actions;

use App\Models\Lead;
use App\Models\Tag;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Tags": set a lead's labels without opening the edit form. New names
 * become tags (gray); recolor them under Outreach → Tags.
 */
class TagLeadAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'tagLead';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Tags')
            ->icon(Heroicon::OutlinedTag)
            ->color('gray')
            ->authorize('update')
            ->modalHeading(fn (Lead $record): string => 'Tags for '.($record->fullName() ?: $record->email))
            ->modalSubmitActionLabel('Save tags')
            ->fillForm(fn (Lead $record): array => ['tags' => $record->tags()->orderBy('name')->pluck('name')->all()])
            ->schema(fn (Lead $record): array => [
                TagsInput::make('tags')
                    ->label('Tags')
                    ->suggestions(Tag::query()->where('workspace_id', $record->workspace_id)->orderBy('name')->pluck('name')->all())
                    ->helperText('Type a tag and press Enter, e.g. hot, agency, wordpress. Pick colors under Outreach → Tags.'),
            ])
            ->action(function (Lead $record, array $data): void {
                $record->syncTagNames($data['tags'] ?? []);

                Notification::make()->title('Tags saved')->success()->send();
            });
    }
}
