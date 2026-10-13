<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\ClientReportController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MailboxOAuthController;
use App\Http\Controllers\MarketingController;
use Illuminate\Support\Facades\Route;

// Public website (config/marketing.php). Off: "/" goes to the app.
Route::get('/', [MarketingController::class, 'home'])->name('home');
Route::get('/pricing', [MarketingController::class, 'pricing'])->name('pricing');
Route::get('/help', [MarketingController::class, 'help'])->name('help.index');
Route::get('/help/{slug}', [MarketingController::class, 'article'])->where('slug', '[a-z0-9-]+')->name('help.article');
Route::get('/terms', [MarketingController::class, 'legal'])->defaults('page', 'terms')->name('legal.terms');
Route::get('/privacy', [MarketingController::class, 'legal'])->defaults('page', 'privacy')->name('legal.privacy');
Route::get('/sitemap.xml', [MarketingController::class, 'sitemap'])->name('sitemap');

Route::get('/invitations/{token}', AcceptInvitationController::class)
    ->middleware('throttle:20,1')
    ->name('invitations.accept');

// Read-only client reports (agencies share the secret link).
Route::get('/reports/{token}', ClientReportController::class)
    ->middleware('throttle:60,1')
    ->name('reports.show');

Route::middleware('auth')->group(function (): void {
    Route::get('/billing/invoices/{invoice}', InvoiceController::class)->name('billing.invoice');
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');

    // Connect Gmail / Microsoft 365 mailboxes with OAuth.
    Route::get('/mailboxes/oauth/{provider}/connect/{workspace}', [MailboxOAuthController::class, 'connect'])
        ->whereIn('provider', ['google', 'microsoft'])
        ->middleware('throttle:20,1')
        ->name('mailboxes.oauth.connect');
    Route::get('/mailboxes/oauth/{provider}/callback', [MailboxOAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'microsoft'])
        ->middleware('throttle:20,1')
        ->name('mailboxes.oauth.callback');
});
