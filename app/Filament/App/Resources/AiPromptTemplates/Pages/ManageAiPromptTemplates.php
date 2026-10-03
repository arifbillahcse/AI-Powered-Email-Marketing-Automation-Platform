<?php

namespace App\Filament\App\Resources\AiPromptTemplates\Pages;

use App\Filament\App\Resources\AiPromptTemplates\AiPromptTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAiPromptTemplates extends ManageRecords
{
    protected static string $resource = AiPromptTemplateResource::class;

    protected static ?string $title = 'AI prompts';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New prompt'),
        ];
    }
}
