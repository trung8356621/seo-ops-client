<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database\DestructiveMigrationGuard;
use RuntimeException;

/**
 * Hard safety gate for PHPUnit / test bootstrap.
 *
 * Validates the *actual* connection driver + database name before any
 * destructive schema fixture may run. APP_ENV=testing alone is NOT enough —
 * the 2026-09-19 incident wiped omi_client while APP_ENV=testing.
 */
final class TestDatabaseGuard
{
    public const BLOCK_PREFIX = 'TEST DATABASE GUARD BLOCKED';

    /**
     * Connection names that must be test-safe when present in config.
     *
     * @var list<string>
     */
    public const WATCHED_CONNECTIONS = [
        'mysql',
        'omi_seo_ai',
        'omi_seeding',
        'sqlite',
    ];

    private DestructiveMigrationGuard $names;

    /**
     * @param  array<string, mixed>|null  $nameGuardConfig  Override for DestructiveMigrationGuard (unit tests)
     */
    public function __construct(?array $nameGuardConfig = null)
    {
        $this->names = new DestructiveMigrationGuard($nameGuardConfig ?? [
            'disposable_database_patterns' => [
                '*_test',
                '*_testing',
                'test_*',
                'phpunit_*',
                'pest_*',
            ],
            'protected_database_names' => [
                'omi_client',
                'omi_seo_ai',
                'omi_seeding',
                'omi_channel',
                'omi_channel__pre_client_split_backup',
                'omi_channel_real',
                'omi_seo_ai_real',
                'omi_client_real',
                'omi_server',
            ],
            'protected_database_patterns' => [
                '*_real',
                '*_prod',
                '*_production',
                'production',
                'prod',
            ],
        ]);
    }

    public static function make(?array $nameGuardConfig = null): self
    {
        return new self($nameGuardConfig);
    }

    public function isAllowed(string $driver, string $database): bool
    {
        try {
            $this->assertAllowed($driver, $database);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @throws RuntimeException
     */
    public function assertAllowed(string $driver, string $database, string $connectionName = ''): void
    {
        $driver = strtolower(trim($driver));
        $database = trim($database);
        $label = $connectionName !== '' ? "connection [{$connectionName}] " : '';

        if ($driver === '') {
            throw new RuntimeException(
                self::BLOCK_PREFIX.": {$label}missing database driver."
            );
        }

        if ($this->isExplicitAllowlistedDatabase($database)) {
            return;
        }

        if ($driver === 'sqlite') {
            if ($this->isSqliteTestSafe($database)) {
                return;
            }

            throw new RuntimeException(
                self::BLOCK_PREFIX."\n"
                ."{$label}sqlite database is not test-safe: [{$database}]\n"
                .'Allowed: :memory:, or a file/name matching *_test / test_* / phpunit_* / pest_*.'
            );
        }

        // mysql / pgsql / etc. — name must be disposable and never protected.
        if ($database === '' || $database === ':memory:') {
            throw new RuntimeException(
                self::BLOCK_PREFIX."\n"
                ."{$label}driver [{$driver}] requires an explicit disposable database name (*_test), got [{$database}]."
            );
        }

        if ($this->names->isProtected($database)) {
            throw new RuntimeException(
                self::BLOCK_PREFIX."\n"
                ."{$label}refusing protected / development database [{$database}] on driver [{$driver}].\n"
                .'APP_ENV=testing does NOT authorize omi_client / omi_seo_ai / similar.\n'
                .'Use sqlite :memory: or a dedicated DB ending in _test.'
            );
        }

        if (! $this->names->isDisposable($database)) {
            throw new RuntimeException(
                self::BLOCK_PREFIX."\n"
                ."{$label}database [{$database}] on driver [{$driver}] is not disposable.\n"
                .'Allowed MySQL/Postgres names must match *_test (or TEST_DB_DATABASE allowlist).'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config  Laravel connection config array
     *
     * @throws RuntimeException
     */
    public function assertConnectionConfig(array $config, string $connectionName): void
    {
        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');
        $this->assertAllowed($driver, $database, $connectionName);
    }

    /**
     * Assert every watched Laravel DB connection currently configured is test-safe.
     *
     * @param  list<string>|null  $connectionNames
     *
     * @throws RuntimeException
     */
    public function assertConfiguredConnectionsAreTestSafe(?array $connectionNames = null): void
    {
        if (! function_exists('config')) {
            throw new RuntimeException(self::BLOCK_PREFIX.': Laravel config() unavailable.');
        }

        $names = $connectionNames ?? self::WATCHED_CONNECTIONS;
        $default = (string) config('database.default', '');
        if ($default !== '' && ! in_array($default, $names, true)) {
            $names[] = $default;
        }

        $core = (string) config('database.core_connection', '');
        if ($core !== '' && ! in_array($core, $names, true)) {
            $names[] = $core;
        }

        foreach (array_values(array_unique($names)) as $name) {
            $config = config('database.connections.'.$name);
            if (! is_array($config)) {
                continue;
            }
            // Skip completely empty placeholders.
            if (($config['driver'] ?? null) === null) {
                continue;
            }
            $this->assertConnectionConfig($config, $name);
        }
    }

    /**
     * Call immediately before Schema::drop* / migrate:fresh style fixtures.
     *
     * @throws RuntimeException
     */
    public function assertBeforeDestructiveSchema(string $connectionName = ''): void
    {
        if ($connectionName !== '') {
            $config = config('database.connections.'.$connectionName);
            if (! is_array($config)) {
                throw new RuntimeException(
                    self::BLOCK_PREFIX.": connection [{$connectionName}] is not configured."
                );
            }
            $this->assertConnectionConfig($config, $connectionName);

            return;
        }

        $this->assertConfiguredConnectionsAreTestSafe();
    }

    public function isProtectedDatabaseName(string $database): bool
    {
        return $this->names->isProtected($database);
    }

    public function isDisposableDatabaseName(string $database): bool
    {
        return $this->names->isDisposable($database);
    }

    private function isSqliteTestSafe(string $database): bool
    {
        if ($database === '' || $database === ':memory:') {
            return true;
        }

        $base = strtolower(basename(str_replace('\\', '/', $database)));
        $baseNoExt = preg_replace('/\.(sqlite3?|db)$/i', '', $base) ?? $base;

        if ($this->names->isProtected($baseNoExt) || $this->names->isProtected($base)) {
            return false;
        }

        return $this->names->isDisposable($baseNoExt)
            || $this->names->isDisposable($base)
            || $this->names->isDisposable($database);
    }

    private function isExplicitAllowlistedDatabase(string $database): bool
    {
        $candidates = [];
        foreach (['TEST_DB_DATABASE', 'DB_TEST_DATABASE', 'SEO_TEST_DATABASE'] as $envKey) {
            $val = env($envKey);
            if (is_string($val) && trim($val) !== '') {
                $candidates[] = trim($val);
            }
        }

        foreach ($candidates as $allowed) {
            if (strcasecmp($allowed, $database) === 0 && $this->names->isDisposable($allowed)) {
                return true;
            }
        }

        return false;
    }
}
