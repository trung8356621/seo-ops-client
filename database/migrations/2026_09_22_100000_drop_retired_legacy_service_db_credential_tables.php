<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Tombstone: drop retired legacy service DB credential tables.
 *
 * Canonical credentials live on service_database_connections (untouched here).
 * Idempotent no-op when tables are already absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_connection_users')) {
            Schema::drop('seo_connection_users');
        }

        if (Schema::hasTable('seo_database_connections')) {
            Schema::drop('seo_database_connections');
        }

        if (Schema::hasTable('seeding_database_connections')) {
            Schema::drop('seeding_database_connections');
        }
    }

    public function down(): void
    {
        // Intentionally empty — tables are permanently retired.
        // Historical create migrations remain for upgrade-history compatibility only.
    }
};
