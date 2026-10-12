<?php

namespace App\Filament\App\Resources\EmailAccounts\Pages;

use App\Filament\App\Resources\EmailAccounts\EmailAccountActions;
use App\Filament\App\Resources\EmailAccounts\EmailAccountResource;
use App\Models\EmailAccount;
use App\Services\Billing\Entitlements;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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

    protected function beforeCreate(): void
    {
        if ($reason = app(Entitlements::class)->denies(Filament::getTenant(), 'mailboxes')) {
            Notification::make()->title('Mailbox limit reached')->body($reason)->danger()->send();
            $this->halt();
        }
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
