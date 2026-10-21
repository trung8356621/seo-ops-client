<?php

declare(strict_types=1);

namespace App\Api\Http\Controllers;

use App\Api\Services\ServiceApiContext;
use App\Core\Addon\AddonEntitlementGate;
use App\Models\Service;
use App\Services\ServiceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only pilot: allowed scopes + local module availability (no secrets / inventory).
 */
final class ApiAccessCapabilitiesController
{
    public function __invoke(ServiceApiContext $context, AddonEntitlementGate $entitlementGate): JsonResponse
    {
        return response()->json([
            'data' => [
                'api_version' => 'v1',
                'gate' => 'api_access',
                'scopes' => $context->scopes(),
                'service' => $context->publicServiceSlug() !== ''
                    ? $context->publicServiceSlug()
                    : $context->serviceSlug(),
                'modules' => $this->moduleAvailability($entitlementGate),
            ],
        ]);
    }

    /**
     * @return array<string, array{available: bool, active: bool, entitled: bool}>
     */
    private function moduleAvailability(AddonEntitlementGate $entitlementGate): array
    {
        $out = [];
        foreach (ServiceIdentity::knownPublicSlugs() as $publicSlug) {
            $service = ServiceIdentity::findService($publicSlug);
            $active = $service instanceof Service && (bool) $service->is_active;
            $available = $service instanceof Service;
            $addonSlug = $this->addonSlugHint($publicSlug, $service);
            $out[$publicSlug] = [
                'available' => $available,
                'active' => $active,
                'entitled' => $addonSlug !== '' ? $entitlementGate->allows($addonSlug) : true,
            ];
        }

        if (! Schema::hasTable('services')) {
            foreach ($out as $slug => $row) {
                $out[$slug] = [
                    'available' => false,
                    'active' => false,
                    'entitled' => $row['entitled'],
                ];
            }
        }

        return $out;
    }

    private function addonSlugHint(string $publicSlug, ?Service $service): string
    {
        if ($service instanceof Service) {
            $ns = (string) ($service->addon_namespace ?? '');
            if ($ns !== '') {
                return strtolower($publicSlug);
            }
        }

        return $publicSlug;
    }
}
