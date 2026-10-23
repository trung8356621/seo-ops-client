<?php

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Exceptions\H1CommandException;
use App\Services\H1\Sites\AdminSiteStatusPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use SoftDeletes;
    use UsesCoreDatabaseConnection;

    protected $fillable = ['user_id', 'subscription_id', 'domain', 'status', 'ssl', 'h1_is_anchor'];

    protected $casts = [
        'ssl' => 'boolean',
        'h1_is_anchor' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Site $site): void {
            $policy = app(AdminSiteStatusPolicy::class);

            if ($site->exists && $site->isDirty('domain')) {
                try {
                    $policy->assertDomainMutable($site);
                } catch (H1CommandException $e) {
                    throw new \RuntimeException($e->getMessage(), 0, $e);
                }
            }

            if ($site->exists && $site->isDirty('h1_is_anchor') && (bool) $site->getOriginal('h1_is_anchor') === true && ! (bool) $site->h1_is_anchor) {
                throw new \RuntimeException('Anchor Admin Site binding cannot be cleared.');
            }

            if ((string) $site->status !== AdminSiteStatusPolicy::STATUS_ACTIVE) {
                return;
            }
            if ($site->exists && (string) $site->getOriginal('status') === AdminSiteStatusPolicy::STATUS_ACTIVE) {
                return;
            }

            try {
                $gate = app(ServiceEntitlementGate::class);
                if (! $gate->enforcementActive()) {
                    return;
                }
                if (! $gate->allows('seo')) {
                    throw new H1CommandException('seo_not_licensed', 'SEO service is not licensed for activation.');
                }
                $policy->assertCanActivate($site, $gate->seoQuota());
            } catch (H1CommandException $e) {
                throw new \RuntimeException($e->getMessage(), 0, $e);
            }
        });

        static::deleting(function (Site $site): void {
            try {
                app(AdminSiteStatusPolicy::class)->assertDeletable($site);
            } catch (H1CommandException $e) {
                throw new \RuntimeException($e->getMessage(), 0, $e);
            }

            if ($site->isForceDeleting()) {
                return;
            }

            $domain = strtolower(trim((string) $site->getOriginal('domain')));

            if ($domain === '' || str_contains($domain, '__trashed__')) {
                return;
            }

            $site->forceFill([
                'domain' => $domain.'__trashed__'.$site->getKey(),
            ])->saveQuietly();
        });

        static::forceDeleting(function (Site $site): void {
            try {
                app(AdminSiteStatusPolicy::class)->assertDeletable($site);
            } catch (H1CommandException $e) {
                throw new \RuntimeException($e->getMessage(), 0, $e);
            }
        });
    }

    public function siteServices()
    {
        return $this->hasMany(SiteService::class);
    }

    /**
     * SiteService ưu tiên mở trang cấu hình dịch vụ (wp-headless trước, sau đó bản ghi đầu tiên).
     */
    public function primarySiteServiceForSettings(): ?SiteService
    {
        if (! $this->relationLoaded('siteServices')) {
            $this->load(['siteServices.service']);
        }

        foreach ($this->siteServices as $siteService) {
            if (($siteService->service?->slug ?? null) === 'wp-headless') {
                return $siteService;
            }
        }

        return $this->siteServices->first();
    }

    /** Site có đang kích hoạt service wp-headless (status active) không. */
    public function hasActiveWpHeadless(): bool
    {
        return $this->siteServices()
            ->whereHas('service', fn ($q) => $q->where('slug', 'wp-headless'))
            ->where('status', 'active')
            ->exists();
    }

    public function metas()
    {
        return $this->hasMany(SiteMeta::class);
    }

    public function taskJobs()
    {
        return $this->hasMany(TaskJob::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Helper để lấy nhanh một giá trị meta
    public function getMeta($key, $default = null)
    {
        $meta = $this->metas()->where('meta_key', $key)->first();

        return $meta ? $meta->meta_value : $default;
    }
}
