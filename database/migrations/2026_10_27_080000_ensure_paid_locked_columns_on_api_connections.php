<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure paid_locked and paid_lock_reasons columns exist on api_connections.
 * Remediates schema drift where migrations 2026_09_08 and 2026_09_10 are recorded
 * in the migrations table but columns were missing on the physical table.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('api_connections')) {
            return;
        }

        if (! $schema->hasColumn('api_connections', 'paid_locked')) {
            $schema->table('api_connections', function (Blueprint $table): void {
                $table->boolean('paid_locked')->default(false)->after('status');
            });
        }

        if (! $schema->hasColumn('api_connections', 'paid_lock_reasons')) {
            $schema->table('api_connections', function (Blueprint $table): void {
                $table->json('paid_lock_reasons')->nullable()->after('paid_locked');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('api_connections')) {
            return;
        }

        $schema->table('api_connections', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('api_connections', 'paid_lock_reasons')) {
                $table->dropColumn('paid_lock_reasons');
            }
            if ($schema->hasColumn('api_connections', 'paid_locked')) {
                $table->dropColumn('paid_locked');
            }
        });
    }
};
