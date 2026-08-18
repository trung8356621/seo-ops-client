<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Addon\AddonDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * Discovers real repo roots without booting Laravel.
 */
final class PeerAddonManifestInventoryTest extends TestCase
{
    private function addonsRoot(): string
    {
        $projectRoot = dirname(__DIR__, 3);
        $addons = $projectRoot.DIRECTORY_SEPARATOR.'addons';
        $this->assertDirectoryExists($addons);

        return $addons;
    }

    public function test_peer_addon_manifests_exist_for_all_target_slugs(): void
    {
        $discovery = new AddonDiscovery();
        $manifests = $discovery->discover([
            $this->addonsRoot(),
        ], ['seo-content-ai', 'wp-headless']);

        $bySlug = [];
        foreach ($manifests as $manifest) {
            $bySlug[$manifest->slug] = $manifest;
        }

        $expected = [
            'search-foundation',
            'seo',
            'search-intelligence',
            'ai-prompt',
            'content',
            'content-projects',
            'media',
            'wordpress',
            'publishing',
            'site-sync',
            'agent',
            'social',
            'commerce',
        ];

        foreach ($expected as $slug) {
            $this->assertArrayHasKey($slug, $bySlug, "Missing peer addon manifest: {$slug}");
            $this->assertNotSame('', $bySlug[$slug]->provider);
            $this->assertFalse($bySlug[$slug]->legacy);
        }
    }

    public function test_legacy_seo_content_ai_still_discoverable_under_compat_addon(): void
    {
        $discovery = new AddonDiscovery();
        $manifests = $discovery->discover([
            $this->addonsRoot(),
        ], ['wp-headless']);

        $slugs = array_map(static fn ($m) => $m->slug, $manifests);
        $this->assertContains('seo-content-ai', $slugs);
        $legacy = null;
        foreach ($manifests as $manifest) {
            if ($manifest->slug === 'seo-content-ai') {
                $legacy = $manifest;
                break;
            }
        }
        $this->assertNotNull($legacy);
        $this->assertTrue($legacy->legacy);
        $this->assertStringContainsString('seo-content-ai-compat', str_replace('\\', '/', $legacy->path));
    }
}