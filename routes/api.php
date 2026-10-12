<?php

use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\WorkspaceApiController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
| REST API v1. Authenticate with "Authorization: Bearer <API key>" (create
| keys in Settings → API keys). Keys belong to one workspace; read keys
| can only GET.
*/

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::middleware(AuthenticateApiToken::class.':read')->group(function (): void {
        Route::get('/me', [WorkspaceApiController::class, 'me']);
        Route::get('/leads', [LeadController::class, 'index']);
        Route::get('/leads/{id}', [LeadController::class, 'show'])->whereNumber('id');
        Route::get('/lists', [WorkspaceApiController::class, 'lists']);
        Route::get('/campaigns', [WorkspaceApiController::class, 'campaigns']);
        Route::get('/campaigns/{id}', [WorkspaceApiController::class, 'showCampaign'])->whereNumber('id');
        Route::get('/replies', [WorkspaceApiController::class, 'replies']);
    });

    Route::middleware(AuthenticateApiToken::class.':write')->group(function (): void {
        Route::post('/leads', [LeadController::class, 'store']);
        Route::patch('/leads/{id}', [LeadController::class, 'update'])->whereNumber('id');
        Route::delete('/leads/{id}', [LeadController::class, 'destroy'])->whereNumber('id');
        Route::post('/lists', [WorkspaceApiController::class, 'createList']);
        Route::post('/campaigns/{id}/pause', [WorkspaceApiController::class, 'pauseCampaign'])->whereNumber('id');
        Route::post('/campaigns/{id}/resume', [WorkspaceApiController::class, 'resumeCampaign'])->whereNumber('id');
        Route::post('/suppressions', [WorkspaceApiController::class, 'suppress']);
    });
});
