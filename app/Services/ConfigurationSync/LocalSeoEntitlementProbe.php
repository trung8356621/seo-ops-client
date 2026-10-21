<?php

declare(strict_types=1);

namespace App\Services\ConfigurationSync;

use App\Services\Gateway\EntitlementPresentationStore;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\SeoEntitlementProbe;

/**
 * Reads the local entitlement presentation written by a future H1 apply.
 * A missing or disconnected snapshot is not an SEO entitlement.
 */
final class LocalSeoEntitlementProbe implements SeoEntitlementProbe
{
    public function __construct(
        private readonly EntitlementPresentationStore $store,
    ) {}

    public function seoServiceEnabled(): bool
    {
        return $this->store->current()->isServiceEntitled('seo');
    }
}
