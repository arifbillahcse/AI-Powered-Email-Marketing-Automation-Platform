<?php

namespace App\Filament\App\Resources\SendingDomains;

use App\Enums\DnsCheckStatus;
use App\Jobs\CheckDomainBlacklists;
use App\Models\SendingDomain;
use App\Services\Dns\DomainHealthChecker;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class SendingDomainActions
{
    public static function checkNow(): Action
    {
        return Action::make('checkNow')
            ->label('Check now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('view')
            ->rateLimit(10)
            ->action(function (SendingDomain $record): void {
                $domain = app(DomainHealthChecker::class)->checkAndStore($record);

                $notification = Notification::make()->title("{$domain->name}: {$domain->status->getLabel()}");

                match ($domain->status) {
                    DnsCheckStatus::Pass => $notification->body('MX, SPF, DKIM and DMARC all look good.')->success(),
                    DnsCheckStatus::Warning => $notification->body('Some records need attention. Open the domain to see fixes.')->warning(),
                    default => $notification->body('Some records are missing or broken. Open the domain to see fixes.')->danger(),
                };

                $notification->send();
            });
    }

    public static function checkBlacklists(): Action
    {
        return Action::make('checkBlacklists')
            ->label('Check blacklists')
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('gray')
            ->authorize('view')
            ->rateLimit(5)
            ->action(function (SendingDomain $record): void {
                CheckDomainBlacklists::dispatch($record->getKey(), Auth::id());

                Notification::make()
                    ->title('Blacklist check queued')
                    ->body('You\'ll get a notification with the result in a minute.')
                    ->success()
                    ->send();
            });
    }
}
