<?php

declare(strict_types=1);

namespace App\Services\H1\Sites;

use App\Models\H1Installation;
use App\Models\Site;
use App\Models\User;
use App\Services\H1\Persistence\InstallationStateRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Registers the permanently bound primary Admin Site (Domain Anchor) for an Installation.
 */
final class PrimaryAnchorSiteService
{
    public function __construct(
        private readonly SiteDomainNormalizer $domains,
        private readonly PublicWebsiteProbe $probe,
        private readonly InstallationStateRepository $installations,
    ) {}

    public function currentAnchorSite(): ?Site
    {
        try {
            if (! Schema::hasTable('sites')) {
                return null;
            }
        } catch (\Throwable) {
            // A fresh installer may be opened before the configured database exists.
            return null;
        }

        if (Schema::hasColumn('sites', 'h1_is_anchor')) {
            $site = Site::query()->where('h1_is_anchor', true)->orderBy('id')->first();
            if ($site instanceof Site) {
                return $site;
            }
        }

        $installation = $this->installations->currentInstallation();
        if (! $installation instanceof H1Installation) {
            return null;
        }

        if ($installation->anchor_site_id !== null) {
            $byId = Site::query()->find($installation->anchor_site_id);
            if ($byId instanceof Site) {
                return $byId;
            }
        }

        $identity = strtolower(trim((string) ($installation->anchor_identity ?? '')));
        if ($identity === '') {
            return null;
        }

        $byDomain = Site::query()->whereRaw('LOWER(domain) = ?', [$identity])->first();

        return $byDomain instanceof Site ? $byDomain : null;
    }

    public function currentAnchorDomain(): ?string
    {
        $site = $this->currentAnchorSite();
        if (! $site instanceof Site) {
            return null;
        }

        $domain = strtolower(trim((string) $site->domain));

        return $domain !== '' ? $domain : null;
    }

    /**
     * Idempotent primary-site registration used by Fresh Installer.
     *
     * @return array{ok: bool, message: string, site_id?: int, domain?: string, created?: bool}
     */
    public function registerFromInstaller(string $rawDomain, int $ownerUserId, bool $ssl = true): array
    {
        try {
            $domain = $this->domains->normalizeAndValidate($rawDomain);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $existingAnchor = $this->currentAnchorSite();
        if ($existingAnchor instanceof Site) {
            $existingDomain = strtolower(trim((string) $existingAnchor->domain));
            if ($existingDomain === $domain) {
                $this->syncInstallationBinding($existingAnchor, $domain);

                return [
                    'ok' => true,
                    'message' => 'Website chính đã được đăng ký trước đó.',
                    'site_id' => (int) $existingAnchor->getKey(),
                    'domain' => $domain,
                    'created' => false,
                ];
            }

            return [
                'ok' => false,
                'message' => 'Website chính đã được gắn cố định ('.$existingDomain.'). Không thể thay đổi Domain Anchor.',
            ];
        }

        try {
            $this->probe->assertPublicWebsiteOnline($domain);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $owner = User::query()->find($ownerUserId);
        if (! $owner instanceof User) {
            return ['ok' => false, 'message' => 'Không tìm thấy tài khoản Administrator để gắn Website chính.'];
        }

        try {
            $site = DB::transaction(function () use ($domain, $ownerUserId, $ssl): Site {
                $existing = Site::query()->whereRaw('LOWER(domain) = ?', [$domain])->first();
                if ($existing instanceof Site) {
                    if (Schema::hasColumn('sites', 'h1_is_anchor') && (bool) $existing->h1_is_anchor) {
                        return $existing;
                    }

                    if (Schema::hasColumn('sites', 'h1_is_anchor')) {
                        Site::query()->where('h1_is_anchor', true)->update(['h1_is_anchor' => false]);
                        $existing->forceFill([
                            'h1_is_anchor' => true,
                            'user_id' => $existing->user_id ?: $ownerUserId,
                            'ssl' => $ssl,
                        ])->saveQuietly();
                    }

                    return $existing->fresh() ?? $existing;
                }

                $attributes = [
                    'user_id' => $ownerUserId,
                    'domain' => $domain,
                    'ssl' => $ssl,
                    'status' => AdminSiteStatusPolicy::STATUS_INACTIVE,
                ];
                if (Schema::hasColumn('sites', 'h1_is_anchor')) {
                    $attributes['h1_is_anchor'] = true;
                }

                return Site::query()->create($attributes);
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Không thể tạo Website chính: '.$e->getMessage()];
        }

        $this->syncInstallationBinding($site, $domain);

        return [
            'ok' => true,
            'message' => 'Đã đăng ký Website chính thành công.',
            'site_id' => (int) $site->getKey(),
            'domain' => $domain,
            'created' => true,
        ];
    }

    public function syncInstallationBinding(?Site $site = null, ?string $domain = null): void
    {
        $site ??= $this->currentAnchorSite();
        if (! $site instanceof Site) {
            return;
        }

        $domain ??= strtolower(trim((string) $site->domain));
        if ($domain === '') {
            return;
        }

        if (! Schema::hasTable('h1_installations')) {
            return;
        }

        $installation = $this->installations->currentInstallation();
        if (! $installation instanceof H1Installation) {
            return;
        }

        $existing = strtolower(trim((string) ($installation->anchor_identity ?? '')));
        if ($existing !== '' && $existing !== $domain) {
            return;
        }

        $installation->anchor_identity = $domain;
        $installation->anchor_site_id = (int) $site->getKey();
        $installation->save();

        if (Schema::hasColumn('sites', 'h1_is_anchor') && ! (bool) $site->h1_is_anchor) {
            Site::query()->where('h1_is_anchor', true)->where('id', '!=', $site->getKey())->update(['h1_is_anchor' => false]);
            $site->forceFill(['h1_is_anchor' => true])->saveQuietly();
        }
    }

}
