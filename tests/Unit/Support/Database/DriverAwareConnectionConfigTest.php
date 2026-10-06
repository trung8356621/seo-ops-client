<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Database;

use App\Support\Database\DriverAwareConnectionConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class DriverAwareConnectionConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => 'core-host',
            'port' => '3306',
            'database' => 'core_db',
            'username' => 'core_user',
            'password' => 'core-pass',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => ['ssl' => true],
        ]);

        Config::set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'laravel',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    public function test_mysql_credentials_keep_charset_collation_strict_and_default_port(): void
    {
        $config = DriverAwareConnectionConfig::fromCredentials([
            'driver' => 'mysql',
            'host' => 'seo-host',
            'database' => 'omi_seo_ai',
            'username' => 'seo_user',
            'password' => 'secret',
        ]);

        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('seo-host', $config['host']);
        $this->assertSame('3306', $config['port']);
        $this->assertSame('omi_seo_ai', $config['database']);
        $this->assertSame('utf8mb4', $config['charset']);
        $this->assertSame('utf8mb4_unicode_ci', $config['collation']);
        $this->assertTrue($config['strict']);
        $this->assertArrayHasKey('engine', $config);
        $this->assertSame('secret', $config['password']);
    }

    public function test_empty_driver_defaults_mysql(): void
    {
        $config = DriverAwareConnectionConfig::fromCredentials([
            'database' => 'omi_seeding',
            'username' => 'root',
            'password' => '',
        ]);

        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('3306', $config['port']);
        $this->assertSame('utf8mb4_unicode_ci', $config['collation']);
    }

    public function test_pgsql_uses_pgsql_template_without_mysql_only_keys(): void
    {
        $config = DriverAwareConnectionConfig::fromCredentials([
            'driver' => 'pgsql',
            'host' => 'pg-host',
            'database' => 'omi_seo_ai',
            'username' => 'pg_user',
            'password' => 'pg-pass',
        ]);

        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame('pg-host', $config['host']);
        $this->assertSame('5432', $config['port']);
        $this->assertSame('omi_seo_ai', $config['database']);
        $this->assertSame('utf8', $config['charset']);
        $this->assertSame('public', $config['search_path']);
        $this->assertSame('prefer', $config['sslmode']);
        $this->assertArrayNotHasKey('collation', $config);
        $this->assertArrayNotHasKey('strict', $config);
        $this->assertArrayNotHasKey('engine', $config);
        $this->assertArrayNotHasKey('unix_socket', $config);
    }

    public function test_overlay_on_core_mysql_keeps_core_credentials_for_auto_database(): void
    {
        $config = DriverAwareConnectionConfig::overlayOnCoreMysql([
            'database' => 'omi_seo_ai_auto_3',
        ]);

        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('core-host', $config['host']);
        $this->assertSame('core_user', $config['username']);
        $this->assertSame('omi_seo_ai_auto_3', $config['database']);
        $this->assertSame('utf8mb4_unicode_ci', $config['collation']);
    }
}
