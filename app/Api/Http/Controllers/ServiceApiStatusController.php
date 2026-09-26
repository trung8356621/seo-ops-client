<?php

declare(strict_types=1);

namespace App\Api\Http\Controllers;

use App\Api\Services\ServiceApiContext;
use App\Services\ServiceIdentity;
use Illuminate\Http\JsonResponse;

/**
 * Foundation status probe — no business data, no secrets.
 */
final class ServiceApiStatusController
{
    public function __invoke(string $service, ServiceApiContext $context): JsonResponse
    {
        $publicSlug = ServiceIdentity::publicSlugForCatalog((string) $context->service->slug);

        return response()->json([
            'data' => [
                'service' => $publicSlug !== '' ? $publicSlug : $service,
                'active' => (bool) $context->service->is_active,
                'api_version' => 'v1',
            ],
        ]);
    }
}
