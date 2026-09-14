<?php

declare(strict_types=1);

namespace App\Core\Permissions;

use App\Models\User;

/**
 * Authorization boundary:
 * A) Account scope — parent_id / accountOwnerId (owner_id semantics)
 * B) Addon permission — Spatie roles (Owner = full addon access for own account)
 *
 * Does not use user_meta for security decisions.
 */
final class AddonAuthorization
{
    public function __construct(
        private readonly AddonPermissionRegistry $registry,
    ) {}

    /**
     * Account owner of the given user's tenant scope.
     */
    public function accountOwnerId(User $user): ?int
    {
        return $user->accountOwnerId();
    }

    /**
     * Whether $actor may act within $targetUser's account scope.
     */
    public function sharesAccountScope(User $actor, User $targetUser): bool
    {
        $actorOwner = $this->accountOwnerId($actor);
        $targetOwner = $this->accountOwnerId($targetUser);

        if ($actorOwner === null || $targetOwner === null) {
            return false;
        }

        return $actorOwner === $targetOwner;
    }

    /**
     * Coarse addon entry: Owner of account always allowed; Staff need any registered addon role.
     */
    public function canAccessAddon(User $user, string $addonSlug): bool
    {
        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        if ($user->isOwner() || (string) $user->role === User::ROLE_ADMIN) {
            return true;
        }

        $roles = $this->registry->rolesFor($addonSlug);
        if ($roles === []) {
            return false;
        }

        $this->registry->ensureSynced();

        return $user->hasAnyRole($roles);
    }

    /**
     * Staff must hold the Spatie role. Owner of account is treated as having full addon capability
     * unless an explicit override role is requested for rank simulation (caller decides).
     */
    public function hasAddonRole(User $user, string $namespacedRole, bool $ownerBypass = true): bool
    {
        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        if ($ownerBypass && $user->isOwner()) {
            return true;
        }

        if (! $this->registry->permissionTablesReady()) {
            return false;
        }

        $this->registry->ensureSynced();

        return $user->hasRole($namespacedRole);
    }

    /**
     * @param  list<string>  $namespacedRoles
     */
    public function hasAnyAddonRole(User $user, array $namespacedRoles, bool $ownerBypass = true): bool
    {
        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        if ($ownerBypass && $user->isOwner()) {
            return true;
        }

        if ($namespacedRoles === [] || ! $this->registry->permissionTablesReady()) {
            return false;
        }

        $this->registry->ensureSynced();

        return $user->hasAnyRole($namespacedRoles);
    }
}
