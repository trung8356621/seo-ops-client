<?php

declare(strict_types=1);

use App\Core\Permissions\SeoRoleAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final removal of legacy users.seo_role.
 * Spatie seo.* roles are the only SSOT (see SeoRoleAssignment).
 */
return new class extends Migration
{
    private function connectionName(): string
    {
        return (string) config('database.core_connection', config('database.default'));
    }

    public function up(): void
    {
        $connection = $this->connectionName();

        if (Schema::connection($connection)->hasTable('users')
            && Schema::connection($connection)->hasColumn('users', 'seo_role')
        ) {
            // Last-chance idempotent backfill before drop.
            try {
                app(SeoRoleAssignment::class)->backfillFromLegacyColumnIfPresent();
            } catch (\Throwable) {
                // Permission tables / registry may be unavailable in partial migrate contexts.
            }

            Schema::connection($connection)->table('users', function (Blueprint $table): void {
                $table->dropColumn('seo_role');
            });
        }
    }

    public function down(): void
    {
        $connection = $this->connectionName();

        if (! Schema::connection($connection)->hasTable('users')) {
            return;
        }

        if (Schema::connection($connection)->hasColumn('users', 'seo_role')) {
            return;
        }

        Schema::connection($connection)->table('users', function (Blueprint $table): void {
            $table->string('seo_role', 50)
                ->nullable()
                ->after('role');
        });
    }
};
