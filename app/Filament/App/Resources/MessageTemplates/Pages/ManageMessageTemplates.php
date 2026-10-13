<?php

namespace App\Filament\App\Resources\MessageTemplates\Pages;

use App\Filament\App\Resources\MessageTemplates\MessageTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMessageTemplates extends ManageRecords
{
    protected static string $resource = MessageTemplateResource::class;

    protected static ?string $title = 'Message templates';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New template'),
        ];
    }
}
