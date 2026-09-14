<?php

declare(strict_types=1);

namespace App\Core\Permissions;

/**
 * Immutable catalog of roles/permissions for one addon slug.
 */
final class AddonPermissionCatalog
{
    /**
     * @param  list<string>  $roles  Namespaced role names (e.g. seo.manager)
     * @param  list<string>  $permissions  Namespaced permission names
     */
    public function __construct(
        public readonly string $addonSlug,
        public readonly array $roles = [],
        public readonly array $permissions = [],
    ) {}
}
