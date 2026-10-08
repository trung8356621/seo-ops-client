<?php

declare(strict_types=1);

namespace App\IndustryContext;

use App\Models\Site;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryContextKeyResolver;

final class SiteIndustryContextKeyResolver implements IndustryContextKeyResolver
{
    public function keyForSite(int $siteId): ?string
    {
        if ($siteId <= 0) {
            return null;
        }

        $site = Site::query()->find($siteId);
        $key = trim((string) $site?->getMeta('seo_industry_context_key'));

        return $key === '' ? null : $key;
    }
}
