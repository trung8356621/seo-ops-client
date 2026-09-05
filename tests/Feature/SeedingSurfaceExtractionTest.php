<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage;
use Omnichannel\Addons\Seeding\Providers\SeedingPanelProvider;
use Tests\TestCase;

/**
 * Canonical Seeding surface at /seeding — panel registration + gates (no RefreshDatabase).
 *
 * Full HTTP create/edit flows are verified manually against seo-ops.test (sqlite migration
 * suite currently fails on unrelated SEO prompt column drops).
 */
final class SeedingSurfaceExtractionTest extends TestCase
{
    public function test_seeding_panel_is_registered_with_path_seeding(): void
    {
        $panel = Filament::getPanel('seeding');

        self::assertSame('seeding', $panel->getId());
        self::assertSame('seeding', $panel->getPath());
        self::assertContains(SeedingTopicsPage::class, $panel->getPages());
    }

    public function test_seeding_panel_provider_is_bootstrapped(): void
    {
        self::assertTrue(class_exists(SeedingPanelProvider::class));
        self::assertContains(
            SeedingPanelProvider::class,
            array_map('strval', array_keys(app()->getLoadedProviders())),
        );
    }

    public function test_canonical_and_legacy_api_route_names_exist(): void
    {
        self::assertTrue(Route::has('seeding.bootstrap'));
        self::assertTrue(Route::has('seeding.health'));
        self::assertTrue(Route::has('seeding.topics.index'));
        self::assertTrue(Route::has('seo.seeding-topics.index'));
        self::assertTrue(Route::has('seeding.legacy.seo-main.topics'));
        self::assertTrue(Route::has('seeding.legacy.seo-hash.topics'));
    }

    public function test_database_config_has_omi_seeding_connection(): void
    {
        self::assertArrayHasKey('omi_seeding', config('database.connections'));
        self::assertSame('omi_seeding', config('database.connections.omi_seeding.database'));
        self::assertNotSame('omi_seo_ai', config('database.connections.omi_seeding.database'));
    }

    public function test_workspace_page_url_targets_seeding_panel_root(): void
    {
        $url = SeedingTopicsPage::getUrl(panel: 'seeding', isAbsolute: false);

        self::assertSame('/seeding', $url);
        self::assertSame('/', SeedingTopicsPage::getRoutePath());
    }

    public function test_guest_is_redirected_from_seeding(): void
    {
        $response = $this->get('/seeding');

        $response->assertRedirect();
    }

    public function test_owner_can_access_seeding_panel_gate(): void
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        self::assertTrue($user->canAccessPanel(Filament::getPanel('seeding')));
    }

    public function test_staff_without_seo_role_can_access_seeding_panel_gate(): void
    {
        $user = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'status' => User::STATUS_NORMAL,
        ]);

        self::assertTrue($user->canAccessPanel(Filament::getPanel('seeding')));
        self::assertFalse($user->canAccessPanel(Filament::getPanel('seo-main')));
    }

    public function test_blocked_user_cannot_access_seeding_panel_gate(): void
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_BLOCK,
        ]);

        self::assertFalse($user->canAccessPanel(Filament::getPanel('seeding')));
    }
}
