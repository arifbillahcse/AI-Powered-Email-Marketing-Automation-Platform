<?php

namespace App\Filament\App\Resources\Leads\Pages;

use App\Filament\App\Resources\Leads\LeadResource;
use App\Services\Billing\Entitlements;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    protected function beforeCreate(): void
    {
        if ($reason = app(Entitlements::class)->denies(Filament::getTenant(), 'leads')) {
            Notification::make()->title('Lead limit reached')->body($reason)->danger()->send();
            $this->halt();
        }
    }
}
