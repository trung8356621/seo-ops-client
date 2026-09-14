<?php

declare(strict_types=1);

namespace App\Core\Permissions;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Bridge legacy users.seo_role ↔ Spatie seo.* roles.
 *
 * Dual-write keeps seo_role for query compatibility until callers migrate.
 * Authorization prefers Spatie; falls back to seo_role when tables/roles missing.
 */
final class LegacySeoRoleBridge
{
    public const ADDON = 'seo';

    public const ROLE_MANAGER = 'seo.manager';

    public const ROLE_PLANNER = 'seo.planner';

    public const ROLE_CONTENT_MANAGER = 'seo.content_manager';

    /** @var array<string, string> legacy short → namespaced */
    public const LEGACY_TO_SPATIE = [
        'manager' => self::ROLE_MANAGER,
        'planner' => self::ROLE_PLANNER,
        'content_manager' => self::ROLE_CONTENT_MANAGER,
    ];

    /** @var array<string, string> namespaced → legacy short */
    public const SPATIE_TO_LEGACY = [
        self::ROLE_MANAGER => 'manager',
        self::ROLE_PLANNER => 'planner',
        self::ROLE_CONTENT_MANAGER => 'content_manager',
    ];

    public function __construct(
        private readonly AddonPermissionRegistry $registry,
    ) {}

    /**
     * @return list<string>
     */
    public function allSpatieRoles(): array
    {
        return array_values(self::LEGACY_TO_SPATIE);
    }

    public function toSpatie(?string $legacyOrNamespaced): ?string
    {
        if ($legacyOrNamespaced === null) {
            return null;
        }

        $value = strtolower(trim($legacyOrNamespaced));
        if ($value === '') {
            return null;
        }

        if (isset(self::SPATIE_TO_LEGACY[$value])) {
            return $value;
        }

        return self::LEGACY_TO_SPATIE[$value] ?? null;
    }

    public function toLegacy(?string $legacyOrNamespaced): ?string
    {
        $spatie = $this->toSpatie($legacyOrNamespaced);

        return $spatie !== null ? (self::SPATIE_TO_LEGACY[$spatie] ?? null) : null;
    }

    /**
     * Resolve SEO rank short name (manager|planner|content_manager) for authorization.
     */
    public function resolveLegacyRank(User $user): ?string
    {
        if ($this->registry->permissionTablesReady()) {
            try {
                $this->registry->ensureSynced();
                foreach (self::SPATIE_TO_LEGACY as $spatie => $legacy) {
                    if ($user->hasRole($spatie)) {
                        return $legacy;
                    }
                }
            } catch (Throwable) {
                // Fall through to column.
            }
        }

        $column = strtolower(trim((string) ($user->seo_role ?? '')));
        if ($column !== '' && isset(self::LEGACY_TO_SPATIE[$column])) {
            return $column;
        }

        return null;
    }

    /**
     * Assign exactly one SEO Spatie role; sync seo_role column for compatibility.
     */
    public function assign(User $user, ?string $legacyOrNamespaced): void
    {
        $spatie = $this->toSpatie($legacyOrNamespaced);
        $legacy = $this->toLegacy($legacyOrNamespaced);

        if (! $this->registry->permissionTablesReady()) {
            if ($user->seo_role !== $legacy) {
                $user->forceFill(['seo_role' => $legacy])->saveQuietly();
            }

            return;
        }

        $this->registry->ensureSynced();

        $current = $user->roles()
            ->whereIn('name', $this->allSpatieRoles())
            ->pluck('name')
            ->all();

        $toRemove = array_values(array_filter(
            $current,
            static fn (string $name): bool => $spatie === null || $name !== $spatie,
        ));

        if ($toRemove !== []) {
            $user->removeRole(...$toRemove);
        }

        if ($spatie !== null && ! $user->hasRole($spatie)) {
            $user->assignRole($spatie);
        }

        if ((string) ($user->seo_role ?? '') !== (string) $legacy) {
            $user->forceFill(['seo_role' => $legacy])->saveQuietly();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Backfill one user from users.seo_role → Spatie (idempotent).
     */
    public function syncFromLegacyColumn(User $user): bool
    {
        $legacy = strtolower(trim((string) ($user->seo_role ?? '')));
        if ($legacy === '' || ! isset(self::LEGACY_TO_SPATIE[$legacy])) {
            return false;
        }

        $spatie = self::LEGACY_TO_SPATIE[$legacy];
        if (! $this->registry->permissionTablesReady()) {
            return false;
        }

        $this->registry->ensureSynced();

        if ($user->hasRole($spatie)) {
            // Still strip sibling seo roles if somehow multi-assigned.
            $extras = $user->roles()
                ->whereIn('name', $this->allSpatieRoles())
                ->where('name', '!=', $spatie)
                ->pluck('name')
                ->all();
            if ($extras !== []) {
                $user->removeRole(...$extras);
            }

            return false;
        }

        $this->assign($user, $legacy);

        return true;
    }

    /**
     * @return array{scanned: int, assigned: int, skipped: int}
     */
    public function backfillAll(): array
    {
        $scanned = 0;
        $assigned = 0;
        $skipped = 0;

        if (! $this->registry->permissionTablesReady()) {
            return compact('scanned', 'assigned', 'skipped');
        }

        $this->registry->ensureSynced();

        User::query()
            ->whereNotNull('seo_role')
            ->where('seo_role', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function (Collection $users) use (&$scanned, &$assigned, &$skipped): void {
                foreach ($users as $user) {
                    if (! $user instanceof User) {
                        continue;
                    }
                    $scanned++;
                    if ($this->syncFromLegacyColumn($user)) {
                        $assigned++;
                    } else {
                        $skipped++;
                    }
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return compact('scanned', 'assigned', 'skipped');
    }
}
