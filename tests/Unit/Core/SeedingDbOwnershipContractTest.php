<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

final class SeedingDbOwnershipContractTest extends TestCase
{
    public function test_database_config_defines_omi_seeding(): void
    {
        $path = dirname(__DIR__, 2).'/../config/database.php';
        $path = realpath($path) ?: $path;
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString("'omi_seeding'", $source);
        self::assertStringContainsString('SEEDING_DB_DATABASE', $source);
        self::assertStringContainsString("env('SEEDING_TEST_DATABASE', 'omi_seeding')", $source);
    }

    public function test_addon_migration_ownership_maps_seeding_to_omi_seeding(): void
    {
        $config = require dirname(__DIR__, 2).'/../config/addon_migration_ownership.php';
        self::assertIsArray($config);
        self::assertSame('omi_seeding', $config['owners']['seeding']['connection'] ?? null);
        self::assertSame(
            'addons/seeding/database/migrations',
            $config['owners']['seeding']['path'] ?? null,
        );
        self::assertNotSame('omi_seo_ai', $config['owners']['seeding']['connection'] ?? null);
        self::assertNotSame('mysql', $config['owners']['seeding']['connection'] ?? null);
    }

    public function test_db_repository_ownership_lists_omi_seeding(): void
    {
        $path = dirname(__DIR__, 2).'/../docs/architecture/DB_REPOSITORY_OWNERSHIP.json';
        $path = realpath($path) ?: $path;
        self::assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json);
        self::assertContains('omi_seeding', $json['protected_databases'] ?? []);
        self::assertSame(
            'omi_seeding',
            $json['repositories']['omnichannel-addons']['addons']['seeding']['connection']
                ?? $json['repositories']['omnichannel-addons']['seeding_connection']
                ?? null,
        );
    }

    public function test_active_seeding_migrations_have_no_business_php(): void
    {
        $dir = dirname(__DIR__, 3).'/omnichannel-addons/seeding/database/migrations';
        if (! is_dir($dir)) {
            $dir = dirname(__DIR__, 2).'/../addons/seeding/database/migrations';
        }
        $dir = realpath($dir) ?: $dir;
        self::assertDirectoryExists($dir);
        self::assertSame([], glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: []);
    }
}
