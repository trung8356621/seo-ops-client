<?php

declare(strict_types=1);

use App\Api\Http\Controllers\ServiceApiStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Service API (external / integrations)
|--------------------------------------------------------------------------
|
| Canonical prefix (registered in bootstrap/app.php):
|   /api/v1/services/{service}/...
|
| Auth: service.api (AuthenticateServiceApi) — service_api_credentials only.
| Does not use services.service_key.
|
| Addon business endpoints contribute via addons/{slug}/routes/api-services.php
| (transport-only include — Core does not interpret domain keys).
*/

Route::middleware(['service.api', 'throttle:service-api'])
    ->group(function (): void {
        Route::get('{service}/status', ServiceApiStatusController::class)
            ->middleware('service.api.scope:service:read')
            ->name('api.v1.services.status');

        $addonRouteFiles = glob(base_path('addons/*/routes/api-services.php')) ?: [];
        foreach ($addonRouteFiles as $addonRouteFile) {
            if (is_string($addonRouteFile) && is_file($addonRouteFile)) {
                require $addonRouteFile;
            }
        }
    });
