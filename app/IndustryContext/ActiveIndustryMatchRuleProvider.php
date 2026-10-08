<?php

declare(strict_types=1);

namespace App\IndustryContext;

use App\Models\IndustryContextProfile;
use App\Models\Site;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;

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

    public function statusForKey(?string $industryContextKey): string
    {
        $key = trim((string) $industryContextKey);
        if ($key === '') {
            return 'no_match_revision';
        }

        $active = $this->manager->active($key, IndustryContextProfile::TYPE_MATCH);
        if ($active === null) {
            $exists = IndustryContextProfile::query()
                ->where('key', $key)
                ->where('type', IndustryContextProfile::TYPE_MATCH)
                ->exists();

            return $exists ? 'match_revision_inactive' : 'no_match_revision';
        }

        if ($this->manager->isStale($active)) {
            return 'match_revision_stale';
        }

        $rules = $this->rulesForKey($key);
        foreach (IndustryGroupType::values() as $group) {
            foreach ((array) ($rules[$group] ?? []) as $entry) {
                if (is_array($entry) && trim((string) ($entry['canonical'] ?? '')) !== '') {
                    return 'active';
                }
            }
        }

        return 'no_taxonomy_groups';
    }
}
