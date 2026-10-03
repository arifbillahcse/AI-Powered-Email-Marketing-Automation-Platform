<?php

namespace App\Filament\App\Resources\Segments\Pages;

use App\Filament\App\Resources\Segments\SegmentResource;
use Filament\Resources\Pages\EditRecord;

class EditSegment extends EditRecord
{
    protected static string $resource = SegmentResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
