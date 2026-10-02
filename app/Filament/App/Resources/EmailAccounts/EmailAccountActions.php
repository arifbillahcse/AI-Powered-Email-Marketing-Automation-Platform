<?php

namespace App\Filament\App\Resources\EmailAccounts;

use App\Enums\EmailAccountStatus;
use App\Jobs\SendTestEmail;
use App\Models\EmailAccount;
use App\Services\Dns\TrackingDomainVerifier;
use App\Services\Mail\MailboxConnectionTester;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Mailbox actions shared by the table and the edit page.
 */
class EmailAccountActions
{
    public static function testConnection(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->authorize('update')
            ->rateLimit(10)
            ->action(fn (EmailAccount $record) => static::runConnectionTest($record));
    }

    public static function runConnectionTest(EmailAccount $account): bool
    {
        $result = app(MailboxConnectionTester::class)->testAndRecord($account);

        $notification = Notification::make();

        $result->passed()
            ? $notification->title('Connected')->body('SMTP and IMAP logins both work.')->success()
            : $notification->title('Connection failed')->body($result->error())->danger()->persistent();

        $notification->send();

        return $result->passed();
    }

    public static function sendTestEmail(): Action
    {
        return Action::make('sendTestEmail')
            ->label('Send test email')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->authorize('update')
            ->rateLimit(5)
            ->modalDescription('Sends one real email from this mailbox. The result shows up in your notifications.')
            ->modalSubmitActionLabel('Send')
            ->fillForm(fn (): array => ['to' => Auth::user()->email])
            ->schema([
                TextInput::make('to')
                    ->label('Send to')
                    ->email()
                    ->required(),
            ])
            ->action(function (EmailAccount $record, array $data): void {
                SendTestEmail::dispatch($record, $data['to'], Auth::user());

                Notification::make()
                    ->title('Test email queued')
                    ->body('You\'ll get a notification when it\'s sent.')
                    ->success()
                    ->send();
            });
    }

    public static function pause(): Action
    {
        return Action::make('pause')
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->authorize('update')
            ->visible(fn (EmailAccount $record): bool => $record->status !== EmailAccountStatus::Paused)
            ->requiresConfirmation()
            ->modalDescription('Campaigns stop sending from this mailbox until you resume it.')
            ->action(function (EmailAccount $record): void {
                $record->forceFill(['status' => EmailAccountStatus::Paused])->save();
                Notification::make()->title('Mailbox paused')->success()->send();
            });
    }

    public static function resume(): Action
    {
        return Action::make('resume')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->authorize('update')
            ->visible(fn (EmailAccount $record): bool => $record->status === EmailAccountStatus::Paused)
            ->action(function (EmailAccount $record): void {
                // Resume into "active", then re-test so a broken login shows up right away.
                $record->forceFill(['status' => EmailAccountStatus::Active])->save();
                static::runConnectionTest($record);
            });
    }

    public static function verifyTrackingDomain(): Action
    {
        return Action::make('verifyTrackingDomain')
            ->label('Verify tracking domain')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->authorize('update')
            ->visible(fn (EmailAccount $record): bool => filled($record->tracking_domain))
            ->action(function (EmailAccount $record): void {
                $error = app(TrackingDomainVerifier::class)->verify($record);

                $error === null
                    ? Notification::make()->title('Tracking domain verified')->success()->send()
                    : Notification::make()->title('Tracking domain not verified')->body($error)->warning()->persistent()->send();
            });
    }
}
