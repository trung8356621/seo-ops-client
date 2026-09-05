<?php

declare(strict_types=1);

namespace Tests\Unit\Seeding;

use PHPUnit\Framework\TestCase;

/**
 * Guard: Core + Seeding must not ship business-domain migrations for Seeding.
 */
final class SeedingNoBusinessSchemaGuardTest extends TestCase
{
    public function test_client_migrations_do_not_create_seeding_domain_tables(): void
    {
        $dir = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [];

        foreach ($files as $file) {
            $base = basename((string) $file);
            $contents = (string) file_get_contents((string) $file);

            self::assertDoesNotMatchRegularExpression(
                '/Schema::create\(\s*[\'\"]seeding_(topics|assignments|comments|links|quotas)/',
                $contents,
                $base.' must not create Seeding business tables',
            );
        }

        self::assertFileExists(
            $dir.DIRECTORY_SEPARATOR.'2026_09_05_100000_create_seeding_database_connections_table.php',
        );
    }

    public function test_addon_active_migrations_dir_has_no_php_business_files(): void
    {
        $dir = dirname(__DIR__, 4)
            .DIRECTORY_SEPARATOR.'omnichannel-addons'
            .DIRECTORY_SEPARATOR.'seeding'
            .DIRECTORY_SEPARATOR.'database'
            .DIRECTORY_SEPARATOR.'migrations';

        if (! is_dir($dir)) {
            self::markTestSkipped('seeding addon migrations dir missing');
        }

        $php = glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [];
        self::assertSame([], $php, 'Active seeding/database/migrations must stay empty of business PHP');
    }
}
