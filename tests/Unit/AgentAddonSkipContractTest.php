<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Client-side guard: legacy Agent addon stays skipped from discovery by default.
 */
final class AgentAddonSkipContractTest extends TestCase
{
    public function test_default_skip_slugs_include_agent(): void
    {
        $configPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'addons.php';
        self::assertFileExists($configPath);
        $source = (string) file_get_contents($configPath);
        self::assertStringContainsString("env('ADDON_SKIP_SLUGS', 'wp-headless,agent')", $source);
    }

    public function test_admin_agent_redirect_does_not_import_agent_workspace(): void
    {
        $path = dirname(__DIR__, 2)
            .DIRECTORY_SEPARATOR.'app'
            .DIRECTORY_SEPARATOR.'Filament'
            .DIRECTORY_SEPARATOR.'Pages'
            .DIRECTORY_SEPARATOR.'AgentWorkspaceRedirect.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString('Omnichannel\\Addons\\Agent\\', $source);
        self::assertStringContainsString('reference-only', $source);
    }
}
