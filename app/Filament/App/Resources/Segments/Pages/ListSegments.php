<?php

namespace App\Filament\App\Resources\Segments\Pages;

use App\Filament\App\Resources\Segments\SegmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSegments extends ListRecords
{
    protected static string $resource = SegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New segment'),
        ];
    }
}
