<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * New self-registered users default to staff (no owner/team yet).
 * Does not rewrite existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = (string) config('database.core_connection', config('database.default'));
        if (! Schema::connection($connection)->hasTable('users')) {
            return;
        }

        $driver = Schema::connection($connection)->getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::connection($connection)->statement(
                "ALTER TABLE users MODIFY COLUMN role ENUM('admin','owner','staff') NOT NULL DEFAULT 'staff'"
            );

            return;
        }

        // sqlite / others: best-effort default via doctrine-less raw is skipped;
        // application create paths set role=staff explicitly.
    }

    public function down(): void
    {
        $connection = (string) config('database.core_connection', config('database.default'));
        if (! Schema::connection($connection)->hasTable('users')) {
            return;
        }

        $driver = Schema::connection($connection)->getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::connection($connection)->statement(
                "ALTER TABLE users MODIFY COLUMN role ENUM('admin','owner','staff') NOT NULL DEFAULT 'owner'"
            );
        }
    }
};
