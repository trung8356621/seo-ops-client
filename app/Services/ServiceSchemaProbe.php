<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Minimal required-schema probe for Service DB readiness (not full migration audit).
 */
final class ServiceSchemaProbe
{
    /**
     * @return list<string>
     */
    public static function requiredTables(string $publicSlug): array
    {
        return match ($publicSlug) {
            ServiceIdentity::PUBLIC_SEO => ['articles'],
            ServiceIdentity::PUBLIC_SEEDING => ['seeding_topics'],
            ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION => ['sa_packages'],
            default => [],
        };
    }

    public function isReady(string $connectionName, string $publicSlug): bool
    {
        try {
            $this->assertReady($connectionName, $publicSlug);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function assertReady(string $connectionName, string $publicSlug): void
    {
        foreach (self::requiredTables($publicSlug) as $table) {
            if (! Schema::connection($connectionName)->hasTable($table)) {
                throw new RuntimeException(
                    "Schema chưa sẵn sàng: thiếu bảng bắt buộc [{$table}]. Chạy migration cho service trước."
                );
            }
        }
    }
}
