<?php

declare(strict_types=1);

namespace App\Services\H1\Sites;

use App\Models\H1Installation;
use App\Models\Site;
use App\Services\H1\Exceptions\H1CommandException;
use App\Services\H1\Persistence\InstallationStateRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEO domain_quota accounting: only status=active consumes quota.
 */
final class AdminSiteStatusPolicy
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_MAINTENANCE = 'maintenance';

    public function __construct(
        private readonly InstallationStateRepository $installations,
    ) {}

    public function activeCount(?int $exceptSiteId = null): int
    {
        if (! Schema::hasTable('sites')) {
            return 0;
        }

        $query = Site::query()->where('status', self::STATUS_ACTIVE);
        if ($exceptSiteId !== null) {
            $query->where('id', '!=', $exceptSiteId);
        }

        return (int) $query->count();
    }

    public function defaultStatusForNewSite(): string
    {
        return self::STATUS_INACTIVE;
    }

    /**
     * @throws H1CommandException
     */
    public function assertCanActivate(Site $site, ?int $seoQuota): void
    {
        if ($site->exists && (string) $site->getOriginal('status') === self::STATUS_ACTIVE) {
            return;
        }
        if ($seoQuota === null) {
            throw new H1CommandException('quota_undefined', 'SEO domain quota is not defined on the verified entitlement.');
        }
        $exceptId = $site->exists ? (int) $site->getKey() : null;
        if ($this->activeCount($exceptId) + 1 > $seoQuota) {
            throw new H1CommandException('quota_capacity_reached', 'SEO domain quota capacity reached.');
        }
    }

    public function activate(Site $site, ?int $seoQuota): void
    {
        $this->assertCanActivate($site, $seoQuota);
        $site->status = self::STATUS_ACTIVE;
        $site->save();
    }

    public function deactivate(Site $site, string $to = self::STATUS_INACTIVE): void
    {
        if (! in_array($to, [self::STATUS_INACTIVE, self::STATUS_MAINTENANCE], true)) {
            $to = self::STATUS_INACTIVE;
        }
        $site->status = $to;
        $site->save();
    }

    /**
     * One-shot overflow / rotation reset — all Admin Sites → inactive.
     *
     * @return int number of rows changed
     */
    public function resetAllInactive(): int
    {
        if (! Schema::hasTable('sites')) {
            return 0;
        }

        return (int) Site::query()
            ->where('status', '!=', self::STATUS_INACTIVE)
            ->update(['status' => self::STATUS_INACTIVE]);
    }

    public function bindAnchorFromInstallation(?H1Installation $installation = null): void
    {
        $installation ??= $this->installations->currentInstallation();
        if (! $installation instanceof H1Installation) {
            return;
        }
        $identity = trim((string) ($installation->anchor_identity ?? ''));
        if ($identity === '' || ! Schema::hasTable('sites')) {
            return;
        }

        $normalized = strtolower($identity);
        $site = Site::query()
            ->whereRaw('LOWER(domain) = ?', [$normalized])
            ->first();

        if (! $site instanceof Site) {
            return;
        }

        DB::transaction(function () use ($site, $installation): void {
            if (Schema::hasColumn('sites', 'h1_is_anchor')) {
                Site::query()->where('h1_is_anchor', true)->update(['h1_is_anchor' => false]);
                $site->forceFill(['h1_is_anchor' => true])->saveQuietly();
            }
            $installation->anchor_site_id = (int) $site->getKey();
            $installation->save();
        });
    }

    public function isAnchor(Site $site): bool
    {
        if (Schema::hasColumn('sites', 'h1_is_anchor') && (bool) $site->getAttribute('h1_is_anchor')) {
            return true;
        }
        $installation = $this->installations->currentInstallation();
        if (! $installation instanceof H1Installation) {
            return false;
        }
        if ($installation->anchor_site_id !== null && (int) $site->getKey() === (int) $installation->anchor_site_id) {
            return true;
        }
        $identity = strtolower(trim((string) ($installation->anchor_identity ?? '')));
        if ($identity === '') {
            return false;
        }

        return strtolower(trim((string) $site->domain)) === $identity;
    }

    public function assertDeletable(Site $site): void
    {
        if ($this->isAnchor($site)) {
            throw new H1CommandException('anchor_protected', 'Anchor Admin Site cannot be deleted.');
        }
    }
}
