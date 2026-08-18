<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use App\Control\Update\NotConfiguredClientUpdater;
use App\Filament\Pages\ControlServer;
use PHPUnit\Framework\TestCase;

final class PassiveControlContractTest extends TestCase
{
    public function test_control_code_has_zero_polling_or_scheduled_server_checks(): void
    {
        $files = $this->controlPhpFiles();
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach ([
                'Schedule::',
                'withoutOverlapping',
                'github.com',
                'api.github.com',
                'UsageLog',
                'telemetry.',
                'wire:poll',
                'Http::retry(',
                '->retry(',
            ] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    basename($file).' must not contain '.$needle,
                );
            }
        }

        $console = (string) file_get_contents(dirname(__DIR__, 3).'/routes/console.php');
        $this->assertStringNotContainsString('control', strtolower($console));
        $this->assertStringNotContainsString('ops-server', $console);
        $this->assertStringNotContainsString('github', strtolower($console));
    }

    public function test_updater_placeholder_does_not_self_update(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Control/Update/NotConfiguredClientUpdater.php',
        );

        $this->assertStringContainsString('notConfigured', $source);
        $this->assertStringNotContainsString('git pull', $source);
        $this->assertStringNotContainsString('composer update', $source);
        $this->assertStringNotContainsString('latest', $source);

        $resultSource = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Control/Update/ClientUpdateResult.php',
        );
        $this->assertStringContainsString("'not_configured'", $resultSource);
        $this->assertTrue(class_exists(NotConfiguredClientUpdater::class));
    }

    public function test_control_server_page_has_no_unlock_or_check_update_button(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3).'/app/Filament/Pages/ControlServer.php');
        $view = (string) file_get_contents(dirname(__DIR__, 3).'/resources/views/filament/pages/control-server.blade.php');

        $this->assertStringNotContainsString('Unlock', $page.$view);
        $this->assertStringNotContainsString('Check update', $page.$view);
        $this->assertStringNotContainsString('wire:poll', $view);
        $this->assertTrue(class_exists(ControlServer::class));
    }

    public function test_saas_legacy_tables_were_not_dropped(): void
    {
        $dir = dirname(__DIR__, 3).'/database/migrations';
        foreach ([
            '2026_02_07_125322_create_wallets_table.php',
            '2026_02_07_125430_create_transactions_table.php',
            '2026_02_07_125527_create_orders_table.php',
            '2026_02_07_125530_create_invoices_table.php',
            '2026_02_07_133137_create_subscriptions_table.php',
            '2026_02_07_125525_create_service_plans_table.php',
            '2026_02_07_133138_create_usage_logs_table.php',
        ] as $file) {
            $this->assertFileExists($dir.'/'.$file);
        }

        foreach (glob($dir.'/*.php') ?: [] as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/drop_.*(wallets|transactions|orders|invoices|subscriptions|usage_logs|service_plans)/',
                basename((string) $path),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function controlPhpFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $paths = array_merge(
            glob($root.'/app/Control/*.php') ?: [],
            glob($root.'/app/Control/Commands/*.php') ?: [],
            glob($root.'/app/Control/Commands/Handlers/*.php') ?: [],
            glob($root.'/app/Control/Signing/*.php') ?: [],
            glob($root.'/app/Control/Update/*.php') ?: [],
            glob($root.'/app/Control/Exceptions/*.php') ?: [],
            glob($root.'/app/Http/Controllers/Control/*.php') ?: [],
            [
                $root.'/app/Http/Middleware/EnsureClientIsNotLocked.php',
                $root.'/app/Filament/Pages/ControlServer.php',
                $root.'/app/Models/ClientControlState.php',
                $root.'/app/Models/ClientControlCommand.php',
                $root.'/routes/control.php',
                $root.'/config/client_control.php',
            ],
        );

        return array_values(array_filter($paths, 'is_file'));
    }
}
