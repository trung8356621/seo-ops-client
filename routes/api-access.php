<?php

declare(strict_types=1);

use App\Api\Http\Controllers\ApiAccessCapabilitiesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Access Gate (local integrations)
|--------------------------------------------------------------------------
|
| Prefix (bootstrap/app.php): /api/v1
| Auth: service.api — service_api_credentials only (never Control Gate / service_key).
|
*/

Route::middleware(['service.api', 'throttle:service-api'])
    ->group(function (): void {
        Route::get('me/capabilities', ApiAccessCapabilitiesController::class)
            ->middleware('service.api.scope:service:read')
            ->name('api.v1.me.capabilities');
    });
