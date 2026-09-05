<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Addon\AddonDiscovery;
use App\Core\Addon\AddonManifest;
use PHPUnit\Framework\TestCase;

/**
 * CASE A/B/C — addon enablement matrix at manifest / discovery level.
 */
final class AddonEnablementMatrixContractTest extends TestCase
{
    public function test_seeding_manifest_boots_without_seo_requirement(): void
    {
        $path = $this->addonsPath().'/seeding/addon.json';
        self::assertFileExists($path);
        $meta = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($meta);
        self::assertSame([], $meta['requires'] ?? []);
        self::assertTrue((bool) ($meta['register_early'] ?? false));
        self::assertContains(
            'Omnichannel\\Addons\\Seeding\\SeedingServiceProvider',
            array_map('strval', $meta['providers'] ?? []),
        );
        self::assertSame(
            'Omnichannel\\Addons\\Seeding\\Providers\\SeedingPanelProvider',
            (string) ($meta['panel_provider'] ?? ''),
        );
    }

    public function test_discovery_can_skip_seo_while_keeping_seeding(): void
    {
        $discovery = new AddonDiscovery();
        $roots = [$this->addonsPath()];
        $manifests = $discovery->discover($roots, ['seo', 'seo-content-ai', 'search-foundation', 'search-intelligence']);
        $bySlug = [];
        foreach ($manifests as $manifest) {
            self::assertInstanceOf(AddonManifest::class, $manifest);
            $bySlug[$manifest->slug] = $manifest;
        }

        self::assertArrayHasKey('seeding', $bySlug);
        self::assertArrayNotHasKey('seo', $bySlug);
        self::assertArrayNotHasKey('seo-content-ai', $bySlug);
        self::assertTrue($bySlug['seeding']->registerEarly);
    }

    public function test_seo_can_be_present_while_seeding_skipped(): void
    {
        $discovery = new AddonDiscovery();
        $manifests = $discovery->discover([$this->addonsPath()], ['seeding']);
        $slugs = array_map(static fn (AddonManifest $m): string => $m->slug, $manifests);

        self::assertContains('seo', $slugs);
        self::assertNotContains('seeding', $slugs);
    }

    private function addonsPath(): string
    {
        $candidates = [
            dirname(__DIR__, 2).'/addons',
            dirname(__DIR__, 3).'/omnichannel-addons',
            'D:/work/omnichannel-addons',
        ];
        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        self::fail('omnichannel-addons path not found');
    }
}
