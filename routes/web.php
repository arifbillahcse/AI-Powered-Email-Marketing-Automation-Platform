<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

// The public marketing site comes later; send visitors to the app for now.
Route::redirect('/', '/app');

Route::get('/invitations/{token}', AcceptInvitationController::class)
    ->middleware('throttle:20,1')
    ->name('invitations.accept');

Route::middleware('auth')->group(function (): void {
    Route::get('/billing/invoices/{invoice}', InvoiceController::class)->name('billing.invoice');
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
});
