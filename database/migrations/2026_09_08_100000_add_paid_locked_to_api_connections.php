<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative paid-lane lock column on api_connections.
 * Reasons added later (paid_lock_reasons). ai_runtime_health_states.paid_locked is deprecated/non-authoritative.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('api_connections')) {
            return;
        }

        if (! Schema::connection($this->connection)->hasColumn('api_connections', 'paid_locked')) {
            Schema::connection($this->connection)->table('api_connections', function (Blueprint $table): void {
                $table->boolean('paid_locked')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('api_connections')) {
            return;
        }

        if (Schema::connection($this->connection)->hasColumn('api_connections', 'paid_locked')) {
            Schema::connection($this->connection)->table('api_connections', function (Blueprint $table): void {
                $table->dropColumn('paid_locked');
            });
        }
    }
};
