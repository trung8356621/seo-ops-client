<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

/**
 * Isolated guard contract tests — no Laravel app, no live MySQL, no Schema::drop.
 */
final class TestDatabaseGuardTest extends TestCase
{
    private TestDatabaseGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = TestDatabaseGuard::make();
    }

    public function test_sqlite_memory_is_allowed(): void
    {
        $this->guard->assertAllowed('sqlite', ':memory:');
        self::assertTrue($this->guard->isAllowed('sqlite', ':memory:'));
    }

    public function test_disposable_mysql_name_seo_ops_test_is_allowed(): void
    {
        $this->guard->assertAllowed('mysql', 'seo_ops_test');
        self::assertTrue($this->guard->isAllowed('mysql', 'seo_ops_test'));
    }

    public function test_omi_client_is_blocked(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(TestDatabaseGuard::BLOCK_PREFIX);

        $this->guard->assertAllowed('mysql', 'omi_client');
    }

    public function test_omi_seo_ai_is_blocked(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(TestDatabaseGuard::BLOCK_PREFIX);

        $this->guard->assertAllowed('mysql', 'omi_seo_ai');
    }

    public function test_app_env_testing_with_omi_client_still_blocked(): void
    {
        // Simulate PHPUnit APP_ENV=testing — guard must still refuse protected DBs.
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';

        self::assertFalse($this->guard->isAllowed('mysql', 'omi_client'));

        try {
            $this->guard->assertAllowed('mysql', 'omi_client', 'mysql');
            self::fail('Expected omi_client to be blocked under APP_ENV=testing');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(TestDatabaseGuard::BLOCK_PREFIX, $e->getMessage());
            self::assertStringContainsString('omi_client', $e->getMessage());
            self::assertStringContainsString('APP_ENV=testing does NOT authorize', $e->getMessage());
        }
    }

    public function test_destructive_schema_fixture_cannot_execute_when_blocked(): void
    {
        $dropped = false;
        $connectionConfig = [
            'driver' => 'mysql',
            'database' => 'omi_client',
            'host' => '127.0.0.1',
        ];

        try {
            $this->guard->assertConnectionConfig($connectionConfig, 'mysql');
            // Would be Schema::dropIfExists(...) in real fixtures — must never reach here.
            $dropped = true;
        } catch (RuntimeException $e) {
            self::assertStringContainsString(TestDatabaseGuard::BLOCK_PREFIX, $e->getMessage());
        }

        self::assertFalse($dropped, 'Destructive schema must not run when guard blocks the connection');
    }

    public function test_assert_before_destructive_schema_blocks_watched_config(): void
    {
        // Pure config-array path (no Laravel) via assertConnectionConfig.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(TestDatabaseGuard::BLOCK_PREFIX);

        $this->guard->assertConnectionConfig([
            'driver' => 'mysql',
            'database' => 'omi_seo_ai',
        ], 'omi_seo_ai');
    }

    public function test_sqlite_test_file_name_allowed(): void
    {
        $this->guard->assertAllowed('sqlite', 'database/seo_ops_test.sqlite');
        self::assertTrue($this->guard->isAllowed('sqlite', '/tmp/phpunit_guard.sqlite'));
    }

    public function test_non_disposable_mysql_name_blocked(): void
    {
        self::assertFalse($this->guard->isAllowed('mysql', 'random_dev_db'));
    }
}
