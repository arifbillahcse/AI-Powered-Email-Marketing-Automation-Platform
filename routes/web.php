<?php

use App\Http\Controllers\AcceptInvitationController;
use Illuminate\Support\Facades\Route;

// The public marketing site comes later; send visitors to the app for now.
Route::redirect('/', '/app');

Route::get('/invitations/{token}', AcceptInvitationController::class)
    ->middleware('throttle:20,1')
    ->name('invitations.accept');
