<?php

namespace App\Filament\App\Resources\EmailAccounts\Pages;

use App\Filament\App\Resources\EmailAccounts\EmailAccountActions;
use App\Filament\App\Resources\EmailAccounts\EmailAccountResource;
use App\Models\EmailAccount;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmailAccount extends EditRecord
{
    protected const CONNECTION_FIELDS = [
        'email',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
    ];

    protected static string $resource = EmailAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EmailAccountActions::testConnection(),
            EmailAccountActions::sendTestEmail(),
            EmailAccountActions::verifyTrackingDomain(),
            EmailAccountActions::pause(),
            EmailAccountActions::resume(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var EmailAccount $account */
        $account = $this->record;

        if ($account->wasChanged(self::CONNECTION_FIELDS)) {
            EmailAccountActions::runConnectionTest($account);
        }
    }
}
