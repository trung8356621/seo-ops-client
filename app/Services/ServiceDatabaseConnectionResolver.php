<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Generic Core resolver: Service.db_connection ← ServiceDatabaseConnection credentials.
 *
 * Canonical health/test NEVER falls back to env or legacy SEO/Seeding tables.
 */
final class ServiceDatabaseConnectionResolver
{
    /** @var array<string, string> */
    private static array $fingerprints = [];

    public function findService(string $publicOrCatalogSlug): ?Service
    {
        if (in_array($publicOrCatalogSlug, ServiceIdentity::knownPublicSlugs(), true)) {
            return ServiceIdentity::findService($publicOrCatalogSlug);
        }

        if (Schema::hasTable('services')) {
            $row = Service::query()->where('slug', $publicOrCatalogSlug)->first();
            if ($row instanceof Service) {
                return $row;
            }
        }

        return ServiceIdentity::findService(ServiceIdentity::publicSlugForCatalog($publicOrCatalogSlug));
    }

    public function connectionForService(Service $service): ?ServiceDatabaseConnection
    {
        if (! Schema::hasTable('service_database_connections')) {
            return null;
        }

        $row = ServiceDatabaseConnection::query()
            ->where('service_id', $service->id)
            ->where('is_active', true)
            ->first()
            ?? ServiceDatabaseConnection::query()->where('service_id', $service->id)->first();

        return $row instanceof ServiceDatabaseConnection ? $row : null;
    }

    public function resolve(string $publicOrCatalogSlug): ?ServiceDatabaseConnection
    {
        $service = $this->findService($publicOrCatalogSlug);

        return $service instanceof Service ? $this->connectionForService($service) : null;
    }

    public function bootstrap(string $publicOrCatalogSlug, bool $forceReconnect = false): void
    {
        $service = $this->findService($publicOrCatalogSlug);
        if (! $service instanceof Service) {
            throw new RuntimeException("Service [{$publicOrCatalogSlug}] not found.");
        }

        $logical = (string) ($service->db_connection ?: 'mysql');
        $row = $this->connectionForService($service);
        if (! $row instanceof ServiceDatabaseConnection) {
            throw new RuntimeException("Service [{$service->slug}] has no database connection configured.");
        }

        $config = $this->buildConfig($row);
        $fingerprint = md5((string) json_encode($config));
        Config::set('database.connections.'.$logical, $config);

        if (! $forceReconnect && (self::$fingerprints[$logical] ?? null) === $fingerprint) {
            return;
        }

        DB::purge($logical);
        self::$fingerprints[$logical] = $fingerprint;
    }

    public function tryBootstrap(string $publicOrCatalogSlug, bool $forceReconnect = false): bool
    {
        try {
            $this->bootstrap($publicOrCatalogSlug, $forceReconnect);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Explicit form draft test — NO env/legacy fallback.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function testDraftAttributes(array $attributes, string $plainPassword): void
    {
        $model = new ServiceDatabaseConnection([
            'type' => $attributes['type'] ?? 'manual',
            'driver' => $attributes['driver'] ?? 'mysql',
            'host' => $attributes['host'] ?? null,
            'port' => $attributes['port'] ?? null,
            'database' => $attributes['database'] ?? null,
            'username' => $attributes['username'] ?? null,
            'is_active' => true,
        ]);
        // Empty string = intentional no-password; never pull ENV here.
        $model->password = $plainPassword === '' ? null : $plainPassword;

        $this->assertWorks($this->buildConfig($model));
    }

    /**
     * @deprecated Use testDraftAttributes for form tests.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function testAttributes(array $attributes, ?string $plainPasswordOverride = null): void
    {
        $this->testDraftAttributes($attributes, (string) ($plainPasswordOverride ?? ''));
    }

    public function testModel(ServiceDatabaseConnection $connection, ?string $plainPasswordOverride = null): void
    {
        if ($plainPasswordOverride !== null) {
            $clone = $connection->replicate();
            $clone->password = $plainPasswordOverride === '' ? null : $plainPasswordOverride;
            $this->assertWorks($this->buildConfig($clone));

            return;
        }

        $this->assertWorks($this->buildConfig($connection));
    }

    /**
     * Canonical-only health (source always canonical|unavailable).
     *
     * @return array{
     *     configured: bool,
     *     reachable: bool,
     *     connection: string,
     *     database: string,
     *     host: ?string,
     *     port: ?string,
     *     username: ?string,
     *     password_present: bool,
     *     connection_source: string,
     *     last_tested_at: ?string,
     *     last_test_ok: ?bool,
     *     error: ?string
     * }
     */
    public function health(string $publicOrCatalogSlug): array
    {
        $service = $this->findService($publicOrCatalogSlug);
        $logical = (string) ($service?->db_connection
            ?: ServiceIdentity::defaultLogicalConnection(
                ServiceIdentity::publicSlugForCatalog($publicOrCatalogSlug)
            ));

        $row = $service instanceof Service ? $this->connectionForService($service) : null;
        if (! $row instanceof ServiceDatabaseConnection) {
            return [
                'configured' => false,
                'reachable' => false,
                'connection' => $logical,
                'database' => '',
                'host' => null,
                'port' => null,
                'username' => null,
                'password_present' => false,
                'connection_source' => 'unavailable',
                'last_tested_at' => null,
                'last_test_ok' => null,
                'error' => 'not_configured',
            ];
        }

        $meta = [
            'configured' => true,
            'connection' => $logical,
            'database' => (string) ($row->database ?? ''),
            'host' => $row->host !== null ? (string) $row->host : null,
            'port' => $row->port !== null ? (string) $row->port : null,
            'username' => $row->username !== null ? (string) $row->username : null,
            'password_present' => $row->password !== null && $row->password !== '',
            'connection_source' => 'canonical',
            'last_tested_at' => $row->last_tested_at?->toDateTimeString(),
            'last_test_ok' => $row->last_test_ok,
        ];

        try {
            $this->bootstrap(
                ServiceIdentity::publicSlugForCatalog((string) $service->slug),
                forceReconnect: true,
            );
            DB::connection($logical)->getPdo();
            DB::connection($logical)->select('select 1 as ok');

            return $meta + [
                'reachable' => true,
                'error' => null,
            ];
        } catch (Throwable) {
            return $meta + [
                'reachable' => false,
                'error' => 'unreachable',
            ];
        }
    }

    public function healthReport(string $publicSlug): array
    {
        $service = ServiceIdentity::findService($publicSlug);
        $db = $this->health($publicSlug);

        return ServiceHealth::make(
            publicSlug: $publicSlug,
            service: $service,
            databaseConfigured: (bool) $db['configured'],
            databaseReachable: (bool) $db['reachable'],
            database: ($db['database'] ?? '') !== '' ? (string) $db['database'] : null,
            error: $db['error'] ?? null,
            connectionSource: (string) ($db['connection_source'] ?? 'unavailable'),
            host: $db['host'] ?? null,
            port: $db['port'] ?? null,
            username: $db['username'] ?? null,
            passwordPresent: (bool) ($db['password_present'] ?? false),
            lastTestedAt: $db['last_tested_at'] ?? null,
            lastTestOk: $db['last_test_ok'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{action: string, plain: ?string}  $passwordIntent
     */
    public function upsert(
        Service $service,
        array $attributes,
        array $passwordIntent = ['action' => ServiceDatabasePasswordIntent::ACTION_KEEP, 'plain' => null],
    ): ServiceDatabaseConnection {
        $row = ServiceDatabaseConnection::query()->firstOrNew(['service_id' => $service->id]);
        $row->fill([
            'type' => $attributes['type'] ?? $row->type ?? 'manual',
            'driver' => $attributes['driver'] ?? $row->driver ?? 'mysql',
            'host' => $attributes['host'] ?? $row->host,
            'port' => $attributes['port'] ?? $row->port,
            'database' => $attributes['database'] ?? $row->database,
            'username' => $attributes['username'] ?? $row->username,
            'is_active' => (bool) ($attributes['is_active'] ?? true),
        ]);

        match ($passwordIntent['action']) {
            ServiceDatabasePasswordIntent::ACTION_CLEAR => $row->password = null,
            ServiceDatabasePasswordIntent::ACTION_SET => $row->password = $passwordIntent['plain'],
            default => null, // keep existing ciphertext
        };

        $row->service_id = $service->id;
        $row->save();

        return $row->fresh() ?? $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildConfig(ServiceDatabaseConnection $connection): array
    {
        $database = trim((string) ($connection->database ?? ''));
        $username = trim((string) ($connection->username ?? ''));
        if ($database === '' || $username === '') {
            throw new RuntimeException('Thiếu database hoặc username.');
        }

        $mysql = Config::get('database.connections.mysql', []);
        if (! is_array($mysql)) {
            $mysql = [];
        }

        return array_merge($mysql, [
            'driver' => (string) ($connection->driver ?: 'mysql'),
            'host' => filled($connection->host) ? (string) $connection->host : '127.0.0.1',
            'port' => filled($connection->port) ? (string) $connection->port : '3306',
            'database' => $database,
            'username' => $username,
            // null / empty both mean no MySQL password.
            'password' => (string) ($connection->password ?? ''),
            'charset' => $mysql['charset'] ?? 'utf8mb4',
            'collation' => $mysql['collation'] ?? 'utf8mb4_unicode_ci',
            'prefix' => $mysql['prefix'] ?? '',
            'strict' => $mysql['strict'] ?? true,
            'engine' => $mysql['engine'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function assertWorks(array $connection): void
    {
        $name = 'service_db_test_'.md5((string) json_encode([
            'host' => $connection['host'] ?? '',
            'database' => $connection['database'] ?? '',
            'username' => $connection['username'] ?? '',
            'password_len' => strlen((string) ($connection['password'] ?? '')),
        ]));

        Config::set('database.connections.'.$name, $connection);
        DB::purge($name);

        try {
            DB::connection($name)->getPdo();
            DB::connection($name)->select('select 1 as ok');
        } catch (Throwable $e) {
            throw new RuntimeException('Không kết nối được database: '.$e->getMessage(), 0, $e);
        } finally {
            DB::purge($name);
            Config::set('database.connections.'.$name, null);
        }
    }
}
