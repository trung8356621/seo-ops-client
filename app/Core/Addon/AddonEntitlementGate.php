<?php

declare(strict_types=1);

namespace App\Core\Addon;

/**
 * Addon commercial gate. When H1 wires a resolver, it is authoritative.
 * Config flag alone never grants access if a resolver is bound.
 */
final class AddonEntitlementGate
{
    /**
     * @param  callable(string): bool|null  $resolver
     */
    public function __construct(
        private mixed $resolver = null,
    ) {}

    public function allows(string $addonSlug): bool
    {
        if (is_callable($this->resolver)) {
            return (bool) ($this->resolver)($addonSlug);
        }

        if (! (bool) config('addons.entitlement.enabled', false)) {
            return true;
        }

        return true;
    }
}
