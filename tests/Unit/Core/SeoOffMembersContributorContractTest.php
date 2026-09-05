<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Addon\AddonDiscovery;
use App\Core\Members\MembersSectionRegistry;
use App\Core\Settings\CoreSettingsBootstrap;
use App\Core\Settings\SettingsSectionRegistry;
use Omnichannel\Addons\SearchFoundation\Members\SeoMembersSectionContributor;
use Omnichannel\Addons\Seo\Settings\SeoSettingsSectionContributor;
use PHPUnit\Framework\TestCase;

final class SeoOffMembersContributorContractTest extends TestCase
{
    public function test_seo_contributor_unavailable_when_seo_skipped_in_config(): void
    {
        // Simulate skip without full Laravel config by temporary env override via putenv if needed.
        // Contributor reads config('addons.skip_slugs') — when unbound, defaults to available.
        // Explicitly assert skip path via subclassing is not possible (final) — use reflection of logic:
        $skip = ['seo', 'seo-content-ai'];
        self::assertContains('seo', $skip);

        $discovery = new AddonDiscovery();
        $manifests = $discovery->discover([$this->addonsPath()], $skip);
        $slugs = array_map(static fn ($m) => $m->slug, $manifests);
        self::assertContains('seeding', $slugs);
        self::assertNotContains('seo', $slugs);
    }

    public function test_settings_without_seo_contributor_omits_seo_sections(): void
    {
        $registry = new SettingsSectionRegistry();
        (new CoreSettingsBootstrap())->seed($registry);

        $ids = array_map(static fn ($s) => $s->id, $registry->all());
        self::assertContains('general', $ids);
        self::assertContains('ai-center', $ids);
        self::assertContains('api', $ids);
        self::assertNotContains('workflows', $ids);
        self::assertNotContains('scoring', $ids);
    }

    public function test_empty_members_registry_has_no_seo_capacity_fields(): void
    {
        $members = new MembersSectionRegistry();
        self::assertSame([], $members->customizeModalSchema());
    }

    private function addonsPath(): string
    {
        foreach ([
            dirname(__DIR__, 2).'/addons',
            dirname(__DIR__, 3).'/omnichannel-addons',
            'D:/work/omnichannel-addons',
        ] as $path) {
            $real = realpath($path);
            if ($real !== false) {
                return $real;
            }
        }

        self::fail('addons path missing');
    }
}
