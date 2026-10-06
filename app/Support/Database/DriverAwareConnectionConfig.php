<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\Config;

/**
 * Builds Laravel connection arrays from stored credentials without silently
 * forcing MySQL when a driver is already present.
 */
final class DriverAwareConnectionConfig
{
    public const DRIVER_MYSQL = 'mysql';

    public const DRIVER_MARIADB = 'mariadb';

    public const DRIVER_PGSQL = 'pgsql';

    /**
     * @param  array<string, mixed>  $credentials  driver, host, port, database, username, password
     * @return array<string, mixed>
     */
    public static function fromCredentials(array $credentials): array
    {
        $driver = self::normalizeDriver($credentials['driver'] ?? null);
        $base = self::template($driver);

        $overlay = [
            'driver' => $driver,
            'host' => filled($credentials['host'] ?? null) ? (string) $credentials['host'] : '127.0.0.1',
            'port' => filled($credentials['port'] ?? null) ? (string) $credentials['port'] : self::defaultPort($driver),
            'database' => (string) ($credentials['database'] ?? ''),
            'username' => (string) ($credentials['username'] ?? ''),
            'password' => (string) ($credentials['password'] ?? ''),
        ];

        if (self::isMysqlFamily($driver)) {
            return array_merge($base, [
                'charset' => $base['charset'] ?? 'utf8mb4',
                'collation' => $base['collation'] ?? 'utf8mb4_unicode_ci',
                'prefix' => $base['prefix'] ?? '',
                'strict' => $base['strict'] ?? true,
                'engine' => $base['engine'] ?? null,
            ], $overlay);
        }

        $merged = array_merge($base, $overlay);
        unset($merged['collation'], $merged['strict'], $merged['engine'], $merged['unix_socket']);

        return $merged;
    }

    /**
     * Auto / legacy SEO paths: clone core mysql (same server, different database).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function overlayOnCoreMysql(array $overrides): array
    {
        $mysql = Config::get('database.connections.mysql', []);
        if (! is_array($mysql)) {
            $mysql = [];
        }

        $driver = self::normalizeDriver($overrides['driver'] ?? ($mysql['driver'] ?? self::DRIVER_MYSQL));
        if (! self::isMysqlFamily($driver)) {
            return self::fromCredentials(array_merge($mysql, $overrides, ['driver' => $driver]));
        }

        return array_merge($mysql, [
            'driver' => $driver,
            'charset' => $mysql['charset'] ?? 'utf8mb4',
            'collation' => $mysql['collation'] ?? 'utf8mb4_unicode_ci',
            'prefix' => $mysql['prefix'] ?? '',
            'strict' => $mysql['strict'] ?? true,
            'engine' => $mysql['engine'] ?? null,
        ], $overrides, ['driver' => $driver]);
    }

    public static function normalizeDriver(mixed $driver): string
    {
        $value = strtolower(trim((string) ($driver ?? '')));

        return $value === '' ? self::DRIVER_MYSQL : $value;
    }

    public static function defaultPort(string $driver): string
    {
        return match (self::normalizeDriver($driver)) {
            self::DRIVER_PGSQL => '5432',
            default => '3306',
        };
    }

    public static function isMysqlFamily(string $driver): bool
    {
        $driver = self::normalizeDriver($driver);

        return $driver === self::DRIVER_MYSQL || $driver === self::DRIVER_MARIADB;
    }

    /**
     * @return array<string, mixed>
     */
    public static function template(string $driver): array
    {
        $driver = self::normalizeDriver($driver);
        $key = match ($driver) {
            self::DRIVER_MARIADB => 'mariadb',
            self::DRIVER_PGSQL => 'pgsql',
            default => 'mysql',
        };

        $template = Config::get('database.connections.'.$key, []);

        return is_array($template) ? $template : [];
    }
}
