<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database\AddonMigrationRegistrar;
use Tests\TestCase;

/**
 * Fresh Installation must discover Agent Runtime migrations on the core mysql plane.
 * Regression for missing agent_threads / agent_apps after first-run wizard.
 */
final class AgentRuntimeMigrationOwnershipContractTest extends TestCase
{
    public function test_addon_migration_ownership_maps_agent_runtime_to_mysql(): void
    {
        $config = require config_path('addon_migration_ownership.php');
        self::assertIsArray($config);
        self::assertSame('mysql', $config['owners']['agent-runtime']['connection'] ?? null);
        self::assertSame(
            'addons/agent-runtime/database/migrations',
            $config['owners']['agent-runtime']['path'] ?? null,
        );
        self::assertNotSame('omi_seo_ai', $config['owners']['agent-runtime']['connection'] ?? null);
    }

    public function test_registrar_includes_agent_runtime_under_mysql_paths(): void
    {
        $registrar = new AddonMigrationRegistrar;
        $byConnection = $registrar->pathsByConnection();
        self::assertArrayHasKey('mysql', $byConnection);

        $normalized = array_map(
            static fn (string $p): string => str_replace('\\', '/', $p),
            $byConnection['mysql'],
        );

        $matched = array_values(array_filter(
            $normalized,
            static fn (string $p): bool => str_contains($p, 'agent-runtime/database/migrations'),
        ));
        self::assertNotEmpty(
            $matched,
            'Fresh Installation mysql paths must include addons/agent-runtime/database/migrations',
        );
    }

    public function test_agent_runtime_create_migrations_exist(): void
    {
        $dir = base_path('addons/agent-runtime/database/migrations');
        self::assertDirectoryExists($dir);

        foreach ([
            '2026_09_27_200000_create_agent_apps_table.php',
            '2026_09_27_200001_create_agent_threads_table.php',
            '2026_09_27_200002_create_agent_runs_table.php',
            '2026_09_27_200003_create_agent_messages_table.php',
            '2026_09_27_200004_seed_seo_ops_agent_app.php',
        ] as $file) {
            self::assertFileExists($dir.DIRECTORY_SEPARATOR.$file, $file);
        }
    }

    public function test_classifier_routes_agent_runtime_filenames(): void
    {
        $registrar = new AddonMigrationRegistrar;
        self::assertSame(
            'agent-runtime',
            $registrar->classifyFilename('2026_09_27_200001_create_agent_threads_table.php'),
        );
        self::assertSame(
            'agent-runtime',
            $registrar->classifyFilename('2026_09_27_200000_create_agent_apps_table.php'),
        );
    }
}
