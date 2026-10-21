<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Support\RuntimeLogger;

/**
 * Integration boundary for future H1 Client receiver.
 *
 * Call {@see self::afterVerifiedEntitlementApplied()} ONLY after entitlement
 * commands have been authenticated and applied successfully by H1.
 * This class does not implement HMAC / ACK / recovery / machine credentials.
 */
class EntitlementAppliedHook
{
    public function __construct(
        private readonly EntitlementPresentationStore $presentationStore,
        private readonly ServiceGatewayProvisioner $provisioner,
    ) {}

    /**
     * @return list<\App\Services\Gateway\ServiceGatewayProvisionResult>
     */
    public function afterVerifiedEntitlementApplied(VerifiedEntitlementSnapshot $snapshot): array
    {
        $this->presentationStore->save($snapshot);

        RuntimeLogger::info('service_gateway.entitlement_applied', [
            'connected' => $snapshot->connected,
            'license_status' => $snapshot->licenseStatus,
            'product_name' => $snapshot->productName,
            'plan_name' => $snapshot->planName,
            'service_count' => count($snapshot->entitledServices),
            // Never log secrets / installation_id / machine credentials.
        ]);

        return $this->provisioner->provisionFromSnapshot($snapshot);
    }
}
