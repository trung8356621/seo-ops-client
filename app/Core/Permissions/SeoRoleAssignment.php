<?php

declare(strict_types=1);

namespace App\Core\Permissions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Canonical SEO addon roles via Spatie (seo.*).
 * No users.seo_role column — Spatie is the only SSOT.
 */
class SeoRoleAssignment
{
    public const ADDON = 'seo';

    public const ROLE_MANAGER = 'seo.manager';

    public const ROLE_PLANNER = 'seo.planner';

    public const ROLE_CONTENT_MANAGER = 'seo.content_manager';

    /** Short UI / historical names → Spatie */
    public const SHORT_TO_SPATIE = [
        'manager' => self::ROLE_MANAGER,
        'planner' => self::ROLE_PLANNER,
        'content_manager' => self::ROLE_CONTENT_MANAGER,
    ];

    public const SPATIE_TO_SHORT = [
        self::ROLE_MANAGER => 'manager',
        self::ROLE_PLANNER => 'planner',
        self::ROLE_CONTENT_MANAGER => 'content_manager',
    ];

    /** @deprecated Use SeoRoleAssignment::ROLE_* */
    public const LEGACY_TO_SPATIE = self::SHORT_TO_SPATIE;

    /** @deprecated Use SeoRoleAssignment::SPATIE_TO_SHORT */
    public const SPATIE_TO_LEGACY = self::SPATIE_TO_SHORT;

    public function __construct(
        private readonly AddonPermissionRegistry $registry,
    ) {}

    /**
     * @return list<string>
     */
    public function allSpatieRoles(): array
    {
        return array_values(self::SHORT_TO_SPATIE);
    }

    public function toSpatie(?string $shortOrNamespaced): ?string
    {
        if ($shortOrNamespaced === null) {
            return null;
        }

        $value = strtolower(trim($shortOrNamespaced));
        if ($value === '') {
            return null;
        }

        if (isset(self::SPATIE_TO_SHORT[$value])) {
            return $value;
        }

        return self::SHORT_TO_SPATIE[$value] ?? null;
    }

    public function toShort(?string $shortOrNamespaced): ?string
    {
        $spatie = $this->toSpatie($shortOrNamespaced);

        return $spatie !== null ? (self::SPATIE_TO_SHORT[$spatie] ?? null) : null;
    }

    /**
     * Resolve SEO rank short name from Spatie only.
     */
    public function resolveShortRank(User $user): ?string
    {
        if (! $this->registry->permissionTablesReady()) {
            return null;
        }

        try {
            $this->registry->ensureSynced();
            foreach (self::SPATIE_TO_SHORT as $spatie => $short) {
                if ($user->hasRole($spatie)) {
                    return $short;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @deprecated Use resolveShortRank()
     */
    public function resolveLegacyRank(User $user): ?string
    {
        return $this->resolveShortRank($user);
    }

    /**
     * Assign exactly one SEO Spatie role (exclusive within seo.*).
     */
    public function assign(User $user, ?string $shortOrNamespaced): void
    {
        $spatie = $this->toSpatie($shortOrNamespaced);

        if (! $this->registry->permissionTablesReady()) {
            return;
        }

        $this->registry->ensureSynced();

        $current = $user->roles()
            ->whereIn('name', $this->allSpatieRoles())
            ->pluck('name')
            ->map(static fn (mixed $n): string => (string) $n)
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Restrict a User query to holders of any of the given SEO roles (short or Spatie names).
     *
     * @param  Builder<User>  $query
     * @param  list<string>  $shortOrSpatieRoles
     * @return Builder<User>
     */
    public function constrainQueryToRoles(Builder $query, array $shortOrSpatieRoles): Builder
    {
        $names = [];
        foreach ($shortOrSpatieRoles as $role) {
            $spatie = $this->toSpatie($role);
            if ($spatie !== null) {
                $names[] = $spatie;
            }
        }
        $names = array_values(array_unique($names));
        if ($names === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('roles', static function (Builder $roles) use ($names): void {
            $roles->whereIn('name', $names);
        });
    }

    public function userHasRole(User $user, string $shortOrNamespaced): bool
    {
        $spatie = $this->toSpatie($shortOrNamespaced);
        if ($spatie === null) {
            return false;
        }

        if (! $this->registry->permissionTablesReady()) {
            return false;
        }

        try {
            $this->registry->ensureSynced();

            return $user->hasRole($spatie);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $shortOrSpatieRoles
     */
    public function userHasAnyRole(User $user, array $shortOrSpatieRoles): bool
    {
        foreach ($shortOrSpatieRoles as $role) {
            if ($this->userHasRole($user, $role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @deprecated Prefer {@see backfillFromLegacyColumnIfPresent()} — kept for historical migrate/command.
     *
     * @return array{scanned: int, assigned: int, skipped: int}
     */
    public function backfillAll(): array
    {
        return $this->backfillFromLegacyColumnIfPresent();
    }

    /**
     * One-shot backfill from legacy users.seo_role when the column still exists.
     * Used by drop-column migration / permissions:sync-addons --backfill-seo only.
     *
     * @return array{scanned: int, assigned: int, skipped: int}
     */
    public function backfillFromLegacyColumnIfPresent(): array
    {
        $scanned = 0;
        $assigned = 0;
        $skipped = 0;

        $connection = (string) config('database.core_connection', config('database.default'));
        if (! Schema::connection($connection)->hasColumn('users', 'seo_role')) {
            return compact('scanned', 'assigned', 'skipped');
        }

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
                    $legacy = strtolower(trim((string) ($user->getAttributes()['seo_role'] ?? '')));
                    if ($legacy === '' || ! isset(self::SHORT_TO_SPATIE[$legacy])) {
                        $skipped++;

                        continue;
                    }
                    $before = $user->hasRole(self::SHORT_TO_SPATIE[$legacy]);
                    $this->assign($user, $legacy);
                    if ($before) {
                        $skipped++;
                    } else {
                        $assigned++;
                    }
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return compact('scanned', 'assigned', 'skipped');
    }
}
