<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Members\MembersSectionRegistry;
use App\Core\Settings\SettingsSectionRegistry;
use App\Core\Sites\SiteAccess;
use App\Models\ApiConnection;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Core extraction invariants — SEO disabled must not break Core/Seeding ownership.
 */
final class CoreExtractionBoundaryContractTest extends TestCase
{
    public function test_core_user_does_not_import_seo_access_control(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(User::class))->getFileName()
        );
        self::assertStringNotContainsString(
            'use Omnichannel\\Addons\\Seo\\Support\\SeoAccessControl',
            $source,
        );
        self::assertTrue(method_exists(User::class, 'canAccessSeoPanel'));
    }

    public function test_core_site_access_and_registries_exist(): void
    {
        self::assertTrue(class_exists(SiteAccess::class));
        self::assertTrue(class_exists(MembersSectionRegistry::class));
        self::assertTrue(class_exists(SettingsSectionRegistry::class));
        self::assertTrue(class_exists(ApiConnection::class));
    }

    public function test_core_app_models_do_not_import_addon_ai_prompt_models(): void
    {
        $root = dirname(__DIR__, 2).'/../app/Models';
        $root = realpath($root) ?: $root;
        self::assertDirectoryExists($root);

        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
            '/\.php$/i',
        );

        foreach ($iterator as $file) {
            $source = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString(
                'Omnichannel\\Addons\\AiPrompt\\Models\\',
                $source,
                $file->getPathname(),
            );
        }
    }

    public function test_ai_center_does_not_hard_import_seo_article_settings_service(): void
    {
        $path = dirname(__DIR__, 2).'/../addons/ai-prompt/src/Filament/Pages/SeoSettingsAiCenter.php';
        $path = realpath($path) ?: $path;
        if (! is_file($path)) {
            self::markTestSkipped('ai-prompt SeoSettingsAiCenter not mounted in this workspace layout');
        }

        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString(
            'use Omnichannel\\Addons\\Seo\\Services\\SeoCreateArticleSettingsService;',
            $source,
        );
    }
}
