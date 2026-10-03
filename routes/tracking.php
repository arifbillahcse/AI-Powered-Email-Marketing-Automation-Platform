<?php

use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

/*
| Public endpoints used inside sent emails. Loaded without the web middleware
| group: no sessions, cookies or CSRF (one-click unsubscribe POSTs come from
| mail providers, not browsers with a session).
*/

Route::middleware('throttle:tracking')->group(function (): void {
    Route::get('/t/o/{token}.gif', [TrackingController::class, 'open'])->name('tracking.open');
    Route::get('/t/c/{token}', [TrackingController::class, 'click'])->name('tracking.click');
    Route::get('/u/{token}', [TrackingController::class, 'showUnsubscribe'])->name('unsubscribe.show');
    Route::post('/u/{token}', [TrackingController::class, 'unsubscribe'])->name('unsubscribe');
});
