<?php

namespace App\Filament\App\Resources\SendingDomains\Pages;

use App\Filament\App\Resources\SendingDomains\SendingDomainResource;
use App\Models\SendingDomain;
use App\Services\Dns\DomainHealthChecker;
use Filament\Resources\Pages\CreateRecord;

class CreateSendingDomain extends CreateRecord
{
    protected static string $resource = SendingDomainResource::class;

    protected function afterCreate(): void
    {
        /** @var SendingDomain $domain */
        $domain = $this->record;

        app(DomainHealthChecker::class)->checkAndStore($domain);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
