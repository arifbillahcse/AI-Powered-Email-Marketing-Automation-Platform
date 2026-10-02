<?php

namespace App\Filament\App\Resources\SendingDomains\Pages;

use App\Filament\App\Resources\SendingDomains\SendingDomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSendingDomains extends ListRecords
{
    protected static string $resource = SendingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add domain'),
        ];
    }
}
