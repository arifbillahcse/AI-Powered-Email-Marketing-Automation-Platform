<?php

namespace App\Filament\App\Resources\Tags\Pages;

use App\Filament\App\Resources\Tags\TagResource;
use App\Models\Tag;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageTags extends ManageRecords
{
    protected static string $resource = TagResource::class;

    protected static ?string $title = 'Tags';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New tag')
                ->using(function (array $data): Tag {
                    $tag = new Tag($data);
                    $tag->workspace_id = Filament::getTenant()->getKey();
                    $tag->save();

                    return $tag;
                }),
        ];
    }
}
