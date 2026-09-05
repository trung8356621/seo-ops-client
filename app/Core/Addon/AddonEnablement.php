<?php

declare(strict_types=1);

namespace App\Core\Addon;

/**
 * Runtime addon enablement helpers for UI contribution gates.
 * Enablement = AddonRegistry::markEnabled (services.is_active) — not panel URL.
 */
final class AddonEnablement
{
    /**
     * @param  list<string>  $slugs
     */
    public static function anyEnabled(array $slugs, array $alsoRequireNotSkipped = []): bool
    {
        $skip = [];
        try {
            $skip = array_map('strval', (array) config('addons.skip_slugs', []));
        } catch (\Throwable) {
        }

        foreach ($alsoRequireNotSkipped as $slug) {
            if (in_array((string) $slug, $skip, true)) {
                return false;
            }
        }

        foreach ($slugs as $slug) {
            if (in_array((string) $slug, $skip, true)) {
                return false;
            }
        }

        try {
            if (! app()->bound(AddonRegistry::class)) {
                return true;
            }

            /** @var AddonRegistry $addons */
            $addons = app(AddonRegistry::class);
            foreach ($slugs as $slug) {
                if ($addons->isEnabled((string) $slug)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    public static function seoStackEnabled(): bool
    {
        return self::anyEnabled(['seo', 'seo-content-ai'], ['seo', 'seo-content-ai']);
    }
}
