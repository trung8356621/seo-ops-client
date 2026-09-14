<?php

declare(strict_types=1);

use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\LegacySeoRoleBridge;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Ensure SEO/Seeding Spatie roles exist
 * 2) Backfill users.seo_role → Spatie (idempotent)
 * 3) Demote legacy Core role=manager → staff (if any)
 *
 * Does NOT drop users.seo_role or users.manager_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = (string) config('database.core_connection', config('database.default'));
        if (! Schema::connection($connection)->hasTable('roles')
            || ! Schema::connection($connection)->hasTable('model_has_roles')
        ) {
            return;
        }

        /** @var AddonPermissionRegistry $registry */
        $registry = app(AddonPermissionRegistry::class);

        if (! $registry->has('seo')) {
            $registry->register('seo', [
                LegacySeoRoleBridge::ROLE_MANAGER,
                LegacySeoRoleBridge::ROLE_PLANNER,
                LegacySeoRoleBridge::ROLE_CONTENT_MANAGER,
            ]);
        }

        if (! $registry->has('seeding')) {
            $registry->register('seeding', [
                'seeding.manager',
                'seeding.topic_creator',
                'seeding.seeder',
            ]);
        }

        $registry->syncToDatabase();

        app(LegacySeoRoleBridge::class)->backfillAll();

        if (Schema::connection($connection)->hasTable('users')) {
            // Safe even when MySQL enum never allowed 'manager' — update affects 0 rows.
            DB::connection($connection)
                ->table('users')
                ->where('role', 'manager')
                ->update(['role' => User::ROLE_STAFF]);
        }
    }

    public function down(): void
    {
        // Non-destructive: keep Spatie assignments; do not restore role=manager.
    }
};
