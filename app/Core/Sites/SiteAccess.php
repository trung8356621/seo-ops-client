<?php

declare(strict_types=1);

namespace App\Core\Sites;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Addon-neutral site ownership / access for Core, Seeding, SEO consumers.
 *
 * SEO Global Domain bar / DomainContext is intentionally out of scope here.
 */
final class SiteAccess
{
    /**
     * Account owner id used to scope sites.user_id.
     * Queue/system actors may pass an explicit owner id.
     */
    public function accountSiteOwnerId(?User $user = null, ?int $fallbackOwnerId = null): int
    {
        $user ??= Auth::user();
        if ($user instanceof User) {
            $ownerId = $user->accountOwnerId();
            if ($ownerId !== null && $ownerId > 0) {
                return $ownerId;
            }

            return (int) $user->id;
        }

        if ($fallbackOwnerId !== null && $fallbackOwnerId > 0) {
            return $fallbackOwnerId;
        }

        return 0;
    }

    public function shouldScopeToAccountOwner(): bool
    {
        return true;
    }

    /**
     * @return Builder<Site>
     */
    public function accessibleSitesQuery(?User $user = null, ?int $fallbackOwnerId = null): Builder
    {
        $query = Site::query();

        if (! $this->shouldScopeToAccountOwner()) {
            return $query;
        }

        $ownerId = $this->accountSiteOwnerId($user, $fallbackOwnerId);
        if ($ownerId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('user_id', $ownerId);
    }

    /**
     * @return list<int>
     */
    public function accessibleSiteIds(?User $user = null, ?int $fallbackOwnerId = null): array
    {
        return $this->accessibleSitesQuery($user, $fallbackOwnerId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function canAccessSite(int $siteId, ?User $user = null, ?int $fallbackOwnerId = null): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        return $this->accessibleSitesQuery($user, $fallbackOwnerId)->whereKey($siteId)->exists();
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function applyAccessibleSiteScope(
        Builder $query,
        string $column = 'site_id',
        ?User $user = null,
        ?int $fallbackOwnerId = null,
    ): Builder {
        if (! $this->shouldScopeToAccountOwner()) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $fallbackOwnerId);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $siteIds);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function applyAccessibleSiteScopeAllowingUnassigned(
        Builder $query,
        string $column = 'site_id',
        ?User $user = null,
        ?int $fallbackOwnerId = null,
    ): Builder {
        if (! $this->shouldScopeToAccountOwner()) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user, $fallbackOwnerId);
        if ($siteIds === []) {
            return $query->whereNull($column);
        }

        return $query->where(function (Builder $builder) use ($column, $siteIds): void {
            $builder->whereIn($column, $siteIds)->orWhereNull($column);
        });
    }
}
