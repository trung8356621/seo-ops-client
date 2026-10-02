<?php

declare(strict_types=1);

namespace App\IndustryContext;

use App\Models\IndustryContextProfile;
use App\Models\Site;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;

final class ActiveIndustryMatchRuleProvider implements IndustryMatchRuleProvider
{
    public function __construct(private readonly IndustryContextProfileManager $manager) {}

    public function rulesForSite(int $siteId): array
    {
        $site = $siteId > 0 ? Site::query()->find($siteId) : null;

        return $this->rulesForKey($site?->getMeta('seo_industry_context_key'));
    }

    public function rulesForKey(?string $industryContextKey): array
    {
        if (trim((string) $industryContextKey) === '') {
            return [];
        }
        $profile = $this->manager->active((string) $industryContextKey, IndustryContextProfile::TYPE_MATCH);
        if ($profile === null) {
            return [];
        }
        $context = (array) $profile->context_json;

        return [...(array) ($context['taxonomy'] ?? []), ...(array) ($context['topic_rules'] ?? []),
            'aliases' => (array) ($context['aliases'] ?? []), 'ambiguities' => (array) ($context['ambiguities'] ?? [])];
    }

    public function provenanceForKey(?string $industryContextKey): ?array
    {
        $profile = trim((string) $industryContextKey) === '' ? null : $this->manager->active((string) $industryContextKey, IndustryContextProfile::TYPE_MATCH);
        if ($profile === null) {
            return null;
        }

        return ['industry_context_key' => $profile->key, 'match_revision_id' => $profile->getKey(),
            'source_core_id' => $profile->source_core_id, 'source_core_hash' => $profile->source_core_hash,
            'stale' => $this->manager->isStale($profile)];
    }
}
