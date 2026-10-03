<?php

namespace App\Filament\App\Resources\LeadLists\Pages;

use App\Filament\App\Resources\LeadLists\LeadListResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLeadLists extends ManageRecords
{
    protected static string $resource = LeadListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New list'),
        ];
    }
}
