<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Support\Carbon;

/**
 * Verified local entitlement view after a trusted apply (future H1) or test adapter.
 * Never carries machine secrets.
 */
final class VerifiedEntitlementSnapshot
{
    /**
     * @param  array<string, bool>  $entitledServices  public slug => entitled
     */
    public function __construct(
        public readonly bool $connected,
        public readonly string $licenseStatus,
        public readonly ?string $productName = null,
        public readonly ?string $planName = null,
        public readonly ?Carbon $startsAt = null,
        public readonly ?Carbon $endsAt = null,
        public readonly array $entitledServices = [],
    ) {}

    public function isServiceEntitled(string $publicSlug): bool
    {
        return (bool) ($this->entitledServices[$publicSlug] ?? false);
    }

    public static function disconnected(): self
    {
        return new self(
            connected: false,
            licenseStatus: 'disconnected',
            productName: null,
            planName: null,
            startsAt: null,
            endsAt: null,
            entitledServices: [],
        );
    }
}
