<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Email tracking + unsubscribe: no session/cookie/CSRF middleware.
            Route::group([], base_path('routes/tracking.php'));
            // Payment gateway returns and webhooks: no session/CSRF either.
            Route::middleware(SubstituteBindings::class)->group(base_path('routes/billing.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Plain `auth` routes (printable invoices) send guests to the app's login.
        $middleware->redirectGuestsTo(fn () => route('filament.app.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
