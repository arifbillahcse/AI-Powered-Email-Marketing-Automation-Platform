<?php

namespace App\Filament\App\Resources\EmailAccounts\Pages;

use App\Filament\App\Resources\EmailAccounts\EmailAccountResource;
use App\Jobs\TestMailboxConnection;
use App\Models\EmailAccount;
use App\Models\Workspace;
use App\Services\Mail\MailboxProvisioner;
use App\Services\Mail\OAuth\MailboxOAuth;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListEmailAccounts extends ListRecords
{
    protected static string $resource = EmailAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->oauthAction('google', 'Connect Google', Heroicon::OutlinedEnvelope),
            $this->oauthAction('microsoft', 'Connect Microsoft 365', Heroicon::OutlinedEnvelope),
            ActionGroup::make([
                CreateAction::make()->label('Connect with SMTP/IMAP'),
                $this->bulkImportAction(),
            ])
                ->label('Add mailbox')
                ->icon(Heroicon::OutlinedPlus)
                ->button(),
        ];
    }

    protected function oauthAction(string $provider, string $label, Heroicon $icon): Action
    {
        return Action::make('connect'.ucfirst($provider))
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->visible(fn (): bool => app(MailboxOAuth::class)->configured($provider) && $this->canWrite())
            ->url(fn (): string => route('mailboxes.oauth.connect', ['provider' => $provider, 'workspace' => Filament::getTenant()]));
    }

    protected function bulkImportAction(): Action
    {
        return Action::make('bulkImport')
            ->label('Bulk import (CSV)')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => $this->canWrite())
            ->modalHeading('Import mailboxes')
            ->modalDescription(new HtmlString(
                'One mailbox per row, with a header row. Columns: <code>email, from_name, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, imap_host, imap_port, imap_encryption, imap_username, imap_password, daily_limit</code>. '
                .'For Gmail, Microsoft 365 or Zoho you can give <code>provider</code> (google, microsoft, zoho) instead of the server settings. '
                .'Existing addresses are updated. Each mailbox is tested in the background. The file is deleted right after reading.'
            ))
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('CSV file')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->maxSize(1024)
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $file = is_array($data['file']) ? Arr::first($data['file']) : $data['file'];
                $csv = $file instanceof TemporaryUploadedFile ? (string) $file->get() : '';

                // Passwords are in this file: don't leave it on disk.
                if ($file instanceof TemporaryUploadedFile) {
                    $file->delete();
                }

                /** @var Workspace $workspace */
                $workspace = Filament::getTenant();
                $result = app(MailboxProvisioner::class)->import($workspace, $csv);

                foreach ([...$result['created'], ...$result['updated']] as $account) {
                    TestMailboxConnection::dispatch($account->getKey());
                }

                $notification = Notification::make()
                    ->title(count($result['created']).' added, '.count($result['updated']).' updated')
                    ->body($result['errors'] === []
                        ? 'Connection tests are running; failing mailboxes show an error status.'
                        : implode("\n", array_slice($result['errors'], 0, 10)).(count($result['errors']) > 10 ? "\n…and ".(count($result['errors']) - 10).' more.' : ''));

                ($result['errors'] === [] ? $notification->success() : $notification->warning()->persistent())->send();
            });
    }

    protected function canWrite(): bool
    {
        return (bool) Auth::user()?->roleIn(Filament::getTenant())?->canWrite()
            && Auth::user()->can('create', EmailAccount::class);
    }
}
