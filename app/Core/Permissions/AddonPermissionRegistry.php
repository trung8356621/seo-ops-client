<?php

declare(strict_types=1);

namespace App\Core\Permissions;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Generic integration point: addons register namespaced roles/permissions.
 * Core owns persistence foundation; addons own definitions.
 *
 * Idempotent: re-registration does not duplicate DB rows.
 */
final class AddonPermissionRegistry
{
    public const GUARD = 'web';

    /** @var array<string, AddonPermissionCatalog> */
    private array $catalogs = [];

    private bool $synced = false;

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function register(string $addonSlug, array $roles = [], array $permissions = []): void
    {
        $addonSlug = trim($addonSlug);
        if ($addonSlug === '') {
            return;
        }

        $roles = $this->normalizeNamespacedNames($addonSlug, $roles);
        $permissions = $this->normalizeNamespacedNames($addonSlug, $permissions);

        $existing = $this->catalogs[$addonSlug] ?? null;
        if ($existing instanceof AddonPermissionCatalog) {
            $roles = array_values(array_unique([...$existing->roles, ...$roles]));
            $permissions = array_values(array_unique([...$existing->permissions, ...$permissions]));
        }

        $this->catalogs[$addonSlug] = new AddonPermissionCatalog($addonSlug, $roles, $permissions);
        $this->synced = false;
    }

    public function has(string $addonSlug): bool
    {
        return isset($this->catalogs[$addonSlug]);
    }

    /**
     * @return list<string>
     */
    public function rolesFor(string $addonSlug): array
    {
        return $this->catalogs[$addonSlug]->roles ?? [];
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(string $addonSlug): array
    {
        return $this->catalogs[$addonSlug]->permissions ?? [];
    }

    /**
     * @return list<AddonPermissionCatalog>
     */
    public function all(): array
    {
        return array_values($this->catalogs);
    }

    /**
     * Persist registered roles/permissions. Safe to call repeatedly.
     *
     * @return array{roles_created: int, permissions_created: int}
     */
    public function syncToDatabase(): array
    {
        $rolesCreated = 0;
        $permissionsCreated = 0;

        if (! $this->permissionTablesReady()) {
            return ['roles_created' => 0, 'permissions_created' => 0];
        }

        foreach ($this->catalogs as $catalog) {
            foreach ($catalog->roles as $roleName) {
                $role = Role::findOrCreate($roleName, self::GUARD);
                if ($role->wasRecentlyCreated) {
                    $rolesCreated++;
                }
            }

            foreach ($catalog->permissions as $permissionName) {
                $permission = Permission::findOrCreate($permissionName, self::GUARD);
                if ($permission->wasRecentlyCreated) {
                    $permissionsCreated++;
                }
            }
        }

        $this->synced = true;

        return [
            'roles_created' => $rolesCreated,
            'permissions_created' => $permissionsCreated,
        ];
    }

    public function ensureSynced(): void
    {
        if ($this->synced) {
            return;
        }

        $this->syncToDatabase();
    }

    public function permissionTablesReady(): bool
    {
        try {
            $connection = (string) config('database.core_connection', config('database.default'));

            return Schema::connection($connection)->hasTable('roles')
                && Schema::connection($connection)->hasTable('permissions')
                && Schema::connection($connection)->hasTable('model_has_roles');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function normalizeNamespacedNames(string $addonSlug, array $names): array
    {
        $prefix = $addonSlug.'.';
        $out = [];

        foreach ($names as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === '') {
                continue;
            }

            if (! str_starts_with($name, $prefix) && ! str_contains($name, '.')) {
                $name = $prefix.$name;
            }

            $out[] = $name;
        }

        return array_values(array_unique($out));
    }
}
