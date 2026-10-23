<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Standardized Service readiness (no secrets).
 *
 * Commercial entitlement = H1 only. DB readiness = canonical ServiceDatabaseConnection
 * + connect + required schema. Legacy services.is_active is not a commercial/runtime gate.
 *
 * @phpstan-type HealthArray array{
 *     slug: string,
 *     public_slug: string,
 *     name: string,
 *     active: bool,
 *     entitled: bool,
 *     entitlement_state: string,
 *     key_provisioned: bool,
 *     database_configured: bool,
 *     database_reachable: bool,
 *     schema_ready: bool,
 *     database_ready: bool,
 *     runtime_ready: bool,
 *     ready: bool,
 *     status: string,
 *     status_label: string,
 *     db_connection: string,
 *     database: string|null,
 *     host: string|null,
 *     port: string|null,
 *     username: string|null,
 *     password_present: bool,
 *     connection_source: string,
 *     readiness_label: string,
 *     last_tested_at: string|null,
 *     last_test_ok: bool|null,
 *     error: string|null
 * }
 */
final class ServiceHealth
{
    public const STATUS_NOT_ENTITLED = 'not_entitled';

    public const STATUS_NEEDS_CONFIG = 'needs_config';

    public const STATUS_READY = 'ready';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CONNECTION_ERROR = 'connection_error';

    /**
     * @param  'allowed'|'not_entitled'|'suspended'  $entitlementState
     * @return HealthArray
     */
    public static function make(
        string $publicSlug,
        ?\App\Models\Service $service,
        bool $databaseConfigured,
        bool $databaseReachable,
        bool $schemaReady = false,
        string $entitlementState = 'allowed',
        ?string $database = null,
        ?string $error = null,
        string $connectionSource = 'unavailable',
        ?string $host = null,
        ?string $port = null,
        ?string $username = null,
        bool $passwordPresent = false,
        ?string $lastTestedAt = null,
        ?bool $lastTestOk = null,
    ): array {
        $entitled = $entitlementState === 'allowed';
        $key = (bool) ($service?->hasServiceKey());
        $dbConnection = (string) ($service?->db_connection ?: ServiceIdentity::defaultLogicalConnection($publicSlug));
        $databaseReady = $databaseConfigured && $databaseReachable && $schemaReady;
        $runtimeReady = $entitled && $databaseReady;

        $status = match (true) {
            $entitlementState === 'suspended' => self::STATUS_SUSPENDED,
            $entitlementState === 'not_entitled' => self::STATUS_NOT_ENTITLED,
            ! $databaseConfigured => self::STATUS_NEEDS_CONFIG,
            ! $databaseReachable => self::STATUS_CONNECTION_ERROR,
            ! $schemaReady => self::STATUS_NEEDS_CONFIG,
            default => self::STATUS_READY,
        };

        $statusLabel = match ($status) {
            self::STATUS_NOT_ENTITLED => 'Chưa được cấp quyền',
            self::STATUS_NEEDS_CONFIG => 'Đã cấp quyền — cần cấu hình',
            self::STATUS_READY => 'Sẵn sàng',
            self::STATUS_SUSPENDED => 'Đã tạm ngưng',
            self::STATUS_CONNECTION_ERROR => 'Kết nối lỗi',
            default => 'Chưa cấu hình',
        };

        $readinessLabel = match (true) {
            ! $databaseConfigured => 'Chưa cấu hình DB',
            ! $databaseReachable => ($lastTestOk === false || $lastTestedAt !== null)
                ? 'Kết nối lỗi'
                : 'Đã cấu hình — chưa kiểm tra',
            ! $schemaReady => 'Schema chưa sẵn sàng',
            default => 'Đã kết nối',
        };

        return [
            'slug' => (string) ($service?->slug ?? $publicSlug),
            'public_slug' => $publicSlug,
            'name' => ServiceIdentity::displayName($publicSlug),
            // Backward-compat: "active" means commercially entitled (H1), not services.is_active.
            'active' => $entitled,
            'entitled' => $entitled,
            'entitlement_state' => $entitlementState,
            'key_provisioned' => $key,
            'database_configured' => $databaseConfigured,
            'database_reachable' => $databaseReachable,
            'schema_ready' => $schemaReady,
            'database_ready' => $databaseReady,
            'runtime_ready' => $runtimeReady,
            'ready' => $runtimeReady,
            'status' => $status,
            'status_label' => $statusLabel,
            'db_connection' => $dbConnection,
            'database' => $database,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password_present' => $passwordPresent,
            'connection_source' => $connectionSource,
            'readiness_label' => $readinessLabel,
            'last_tested_at' => $lastTestedAt,
            'last_test_ok' => $lastTestOk,
            'error' => $error,
        ];
    }
}
