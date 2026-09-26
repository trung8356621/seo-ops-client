<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Client-side guard: legacy Agent Workspace addon stays skipped and UI removed.
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

    public function test_admin_agent_workspace_redirect_page_is_removed(): void
    {
        $path = dirname(__DIR__, 2)
            .DIRECTORY_SEPARATOR.'app'
            .DIRECTORY_SEPARATOR.'Filament'
            .DIRECTORY_SEPARATOR.'Pages'
            .DIRECTORY_SEPARATOR.'AgentWorkspaceRedirect.php';
        self::assertFileDoesNotExist($path);

        $view = dirname(__DIR__, 2)
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'views'
            .DIRECTORY_SEPARATOR.'filament'
            .DIRECTORY_SEPARATOR.'pages'
            .DIRECTORY_SEPARATOR.'agent-workspace-redirect.blade.php';
        self::assertFileDoesNotExist($view);
    }

    public function test_admin_panel_provider_does_not_import_agent_filament(): void
    {
        $path = dirname(__DIR__, 2)
            .DIRECTORY_SEPARATOR.'app'
            .DIRECTORY_SEPARATOR.'Providers'
            .DIRECTORY_SEPARATOR.'Filament'
            .DIRECTORY_SEPARATOR.'AdminPanelProvider.php';
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString('Omnichannel\\Addons\\Agent\\', $source);
        self::assertStringNotContainsString('AutomationFlowsPage', $source);
    }
}
