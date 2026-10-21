<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\LocalEntitlementPresentation;
use Illuminate\Support\Facades\Schema;

/**
 * Persists / reads safe license presentation for Control Gate.
 */
final class EntitlementPresentationStore
{
    public function current(): VerifiedEntitlementSnapshot
    {
        if (! Schema::hasTable('local_entitlement_presentations')) {
            return VerifiedEntitlementSnapshot::disconnected();
        }

        $row = LocalEntitlementPresentation::query()->orderBy('id')->first();
        if (! $row instanceof LocalEntitlementPresentation || ! $row->connected) {
            return VerifiedEntitlementSnapshot::disconnected();
        }

        $services = $row->entitled_services;
        if (! is_array($services)) {
            $services = [];
        }

        $mapped = [];
        foreach ($services as $slug => $entitled) {
            if (is_string($slug)) {
                $mapped[$slug] = (bool) $entitled;
            }
        }

        return new VerifiedEntitlementSnapshot(
            connected: true,
            licenseStatus: (string) ($row->license_status ?: 'unknown'),
            productName: $row->product_name !== null ? (string) $row->product_name : null,
            planName: $row->plan_name !== null ? (string) $row->plan_name : null,
            startsAt: $row->starts_at,
            endsAt: $row->ends_at,
            entitledServices: $mapped,
        );
    }

    public function save(VerifiedEntitlementSnapshot $snapshot): void
    {
        if (! Schema::hasTable('local_entitlement_presentations')) {
            return;
        }

        $row = LocalEntitlementPresentation::query()->orderBy('id')->first();
        if (! $row instanceof LocalEntitlementPresentation) {
            $row = new LocalEntitlementPresentation;
        }

        $row->fill([
            'connected' => $snapshot->connected,
            'license_status' => $snapshot->licenseStatus,
            'product_name' => $snapshot->productName,
            'plan_name' => $snapshot->planName,
            'starts_at' => $snapshot->startsAt,
            'ends_at' => $snapshot->endsAt,
            'entitled_services' => $snapshot->entitledServices,
            'applied_at' => $snapshot->connected ? now() : null,
        ])->save();
    }
}
