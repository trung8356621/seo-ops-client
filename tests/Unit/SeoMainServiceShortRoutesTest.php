<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SeoMainServiceShortRoutesTest extends TestCase
{
    public function test_short_and_hash_content_operations_routes_both_exist(): void
    {
        $short = Route::getRoutes()->getByName('filament.seo-main.pages.content-operations');
        $hash = Route::getRoutes()->getByName('filament.seo.pages.content-operations');

        $this->assertNotNull($short);
        $this->assertNotNull($hash);
        $this->assertSame('seo/content-operations', $short->uri());
        $this->assertSame('seo/{connection_hash}/content-operations', $hash->uri());
    }

    public function test_keywords_and_content_projects_have_short_routes(): void
    {
        $keywords = Route::getRoutes()->getByName('filament.seo-main.resources.keywords.index');
        $projects = Route::getRoutes()->getByName('filament.seo-main.resources.content-projects.index');

        $this->assertNotNull($keywords);
        $this->assertNotNull($projects);
        $this->assertSame('seo/keywords', $keywords->uri());
        $this->assertSame('seo/content-projects', $projects->uri());
    }

    public function test_admin_automation_flows_route_exists(): void
    {
        $flows = Route::getRoutes()->getByName('filament.admin.pages.automation.flows');
        $this->assertNotNull($flows);
        $this->assertSame('admin/automation/flows', $flows->uri());
    }

    public function test_rewrite_main_service_alias_middleware_is_gone(): void
    {
        $this->assertFalse(class_exists(
            \Omnichannel\Addons\Seo\Http\Middleware\RewriteSeoMainServiceAlias::class,
            false,
        ));
        $this->assertTrue(class_exists(
            \Omnichannel\Addons\Seo\Http\Middleware\ResolveSeoMainServiceContext::class,
        ));
        $this->assertNull(Route::getRoutes()->getByName('seo.panel.main-alias'));
    }

    public function test_root_redirects_to_seo(): void
    {
        $this->get('/')->assertRedirect('/seo');
    }
}
