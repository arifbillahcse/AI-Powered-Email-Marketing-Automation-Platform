<?php

namespace App\Filament\App\Resources\EmailAccounts\Pages;

use App\Filament\App\Resources\EmailAccounts\EmailAccountActions;
use App\Filament\App\Resources\EmailAccounts\EmailAccountResource;
use App\Models\EmailAccount;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailAccount extends CreateRecord
{
    protected static string $resource = EmailAccountResource::class;

    protected static ?string $title = 'Connect mailbox';

    protected function getCreatedNotification(): null
    {
        // The connection test below reports the outcome instead.
        return null;
    }

    protected function afterCreate(): void
    {
        /** @var EmailAccount $account */
        $account = $this->record;

        EmailAccountActions::runConnectionTest($account);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
