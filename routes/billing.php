<?php

use App\Http\Controllers\BillingCallbackController;
use Illuminate\Support\Facades\Route;

/*
| Payment gateway callbacks. Loaded without the web middleware group: the
| gateways POST here cross-site, with no session or CSRF token. Payments are
| verified with the gateway itself, never trusted from the request.
*/

Route::middleware('throttle:60,1')->group(function (): void {
    Route::match(['get', 'post'], '/billing/return/{gateway}/{invoice}', [BillingCallbackController::class, 'return'])
        ->whereIn('gateway', ['stripe', 'sslcommerz', 'bkash'])
        ->name('billing.return');
    Route::post('/billing/webhook/{gateway}', [BillingCallbackController::class, 'webhook'])
        ->whereIn('gateway', ['stripe', 'sslcommerz'])
        ->name('billing.webhook');
});
