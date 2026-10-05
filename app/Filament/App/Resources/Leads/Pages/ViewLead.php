<?php

namespace App\Filament\App\Resources\Leads\Pages;

use App\Filament\App\Actions\SendLeadEmailAction;
use App\Filament\App\Resources\Leads\LeadResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SendLeadEmailAction::make(),
            EditAction::make(),
        ];
    }
}
