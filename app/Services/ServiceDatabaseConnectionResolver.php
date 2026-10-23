<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Support\Database\DriverAwareConnectionConfig;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Generic Core resolver: Service.db_connection ← ServiceDatabaseConnection credentials.
 *
 * Canonical health/test NEVER falls back to env or legacy SEO/Seeding tables.
 * Connection rows are not blocked by legacy is_active flags.
 */
final class ServiceDatabaseConnectionResolver
{
    /** @var array<string, string> */
    private static array $fingerprints = [];

    public function __construct(
        private readonly ServiceSchemaProbe $schemaProbe = new ServiceSchemaProbe,
    ) {}

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

        // UNIQUE(service_id): ignore legacy is_active — valid config must remain usable.
        $row = ServiceDatabaseConnection::query()->where('service_id', $service->id)->first();

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
     * Optionally verifies required schema for the public service slug.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function testDraftAttributes(
        array $attributes,
        string $plainPassword,
        ?string $publicSlugForSchema = null,
    ): void {
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

        $config = $this->buildConfig($model);
        $this->assertWorks($config, $publicSlugForSchema);
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
     *     schema_ready: bool,
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
        $publicSlug = ServiceIdentity::publicSlugForCatalog($publicOrCatalogSlug);
        $service = $this->findService($publicOrCatalogSlug);
        $logical = (string) ($service?->db_connection
            ?: ServiceIdentity::defaultLogicalConnection($publicSlug));

        $row = $service instanceof Service ? $this->connectionForService($service) : null;
        if (! $row instanceof ServiceDatabaseConnection) {
            return [
                'configured' => false,
                'reachable' => false,
                'schema_ready' => false,
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
            $this->bootstrap($publicSlug, forceReconnect: true);
            DB::connection($logical)->getPdo();
            DB::connection($logical)->select('select 1 as ok');
            $schemaReady = $this->schemaProbe->isReady($logical, $publicSlug);

            return $meta + [
                'reachable' => true,
                'schema_ready' => $schemaReady,
                'error' => $schemaReady ? null : 'schema_missing',
            ];
        } catch (Throwable) {
            return $meta + [
                'reachable' => false,
                'schema_ready' => false,
                'error' => 'unreachable',
            ];
        }
    }

    public function healthReport(string $publicSlug): array
    {
        $service = ServiceIdentity::findService($publicSlug);
        $db = $this->health($publicSlug);
        $entitlementState = 'allowed';
        try {
            $entitlementState = app(ServiceEntitlementGate::class)->state($publicSlug);
        } catch (Throwable) {
            $entitlementState = 'allowed';
        }

        return ServiceHealth::make(
            publicSlug: $publicSlug,
            service: $service,
            databaseConfigured: (bool) $db['configured'],
            databaseReachable: (bool) $db['reachable'],
            schemaReady: (bool) ($db['schema_ready'] ?? false),
            entitlementState: $entitlementState,
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
            // Legacy column retained; never used as a commercial/runtime gate.
            'is_active' => true,
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

        return DriverAwareConnectionConfig::fromCredentials([
            'driver' => $connection->driver,
            'host' => $connection->host,
            'port' => $connection->port,
            'database' => $database,
            'username' => $username,
            'password' => (string) ($connection->password ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function assertWorks(array $connection, ?string $publicSlugForSchema = null): void
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
            if (is_string($publicSlugForSchema) && $publicSlugForSchema !== '') {
                $this->schemaProbe->assertReady($name, $publicSlugForSchema);
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Schema chưa sẵn sàng') || str_contains($message, 'Thiếu database')) {
                throw $e instanceof RuntimeException
                    ? $e
                    : new RuntimeException($message, 0, $e);
            }
            if (str_contains(strtolower($message), 'unknown database')) {
                $dbName = (string) ($connection['database'] ?? '');
                throw new RuntimeException(
                    'Database chưa tồn tại'.($dbName !== '' ? " [{$dbName}]" : '').'. Hãy tạo database trước (hệ thống không tự tạo). Chi tiết: '.$message,
                    0,
                    $e,
                );
            }

            throw new RuntimeException('Không kết nối được database: '.$message, 0, $e);
        } finally {
            DB::purge($name);
            Config::set('database.connections.'.$name, null);
        }
    }
}
