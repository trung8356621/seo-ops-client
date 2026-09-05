<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Standardized Service readiness (no secrets).
 *
 * Canonical DB health uses ONLY ServiceDatabaseConnection — never env/legacy paint.
 *
 * @phpstan-type HealthArray array{
 *     slug: string,
 *     public_slug: string,
 *     name: string,
 *     active: bool,
 *     key_provisioned: bool,
 *     database_configured: bool,
 *     database_reachable: bool,
 *     database_ready: bool,
 *     runtime_ready: bool,
 *     ready: bool,
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
    /**
     * @return HealthArray
     */
    public static function make(
        string $publicSlug,
        ?\App\Models\Service $service,
        bool $databaseConfigured,
        bool $databaseReachable,
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
        $active = (bool) ($service?->is_active);
        $key = (bool) ($service?->hasServiceKey());
        $dbConnection = (string) ($service?->db_connection ?: ServiceIdentity::defaultLogicalConnection($publicSlug));
        $databaseReady = $databaseConfigured && $databaseReachable;

        $runtimeReady = $publicSlug === ServiceIdentity::PUBLIC_SEEDING
            ? ($active && $key)
            : ($active && $key && $databaseReady);

        $readinessLabel = match (true) {
            ! $databaseConfigured => 'Chưa cấu hình',
            $databaseReachable => 'Đã kết nối',
            $lastTestOk === false => 'Kết nối lỗi',
            $lastTestedAt === null => 'Đã cấu hình — chưa kiểm tra',
            default => 'Kết nối lỗi',
        };

        return [
            'slug' => (string) ($service?->slug ?? $publicSlug),
            'public_slug' => $publicSlug,
            'name' => ServiceIdentity::displayName($publicSlug),
            'active' => $active,
            'key_provisioned' => $key,
            'database_configured' => $databaseConfigured,
            'database_reachable' => $databaseReachable,
            'database_ready' => $databaseReady,
            'runtime_ready' => $runtimeReady,
            'ready' => $runtimeReady,
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
