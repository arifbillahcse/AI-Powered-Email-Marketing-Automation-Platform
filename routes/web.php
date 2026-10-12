<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MailboxOAuthController;
use Illuminate\Support\Facades\Route;

// The public marketing site comes later; send visitors to the app for now.
Route::redirect('/', '/app');

Route::get('/invitations/{token}', AcceptInvitationController::class)
    ->middleware('throttle:20,1')
    ->name('invitations.accept');

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
