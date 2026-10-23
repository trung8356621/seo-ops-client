<?php

declare(strict_types=1);

namespace App\Api\Http\Controllers;

use App\Api\Services\ServiceApiContext;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\ServiceIdentity;
use Illuminate\Http\JsonResponse;

/**
 * Foundation status probe — no business data, no secrets.
 */
final class ServiceApiStatusController
{
    public function __invoke(string $service, ServiceApiContext $context, ServiceEntitlementGate $gate): JsonResponse
    {
        $publicSlug = ServiceIdentity::publicSlugForCatalog((string) $context->service->slug);

        return response()->json([
            'data' => [
                'service' => $publicSlug !== '' ? $publicSlug : $service,
                'active' => $gate->allows($publicSlug !== '' ? $publicSlug : $service),
                'api_version' => 'v1',
            ],
        ]);
    }
}
