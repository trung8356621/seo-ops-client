<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical ops-server service instance secret (not in services.config).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        if (! Schema::hasColumn('services', 'service_key')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->text('service_key')->nullable()->after('config');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services') && Schema::hasColumn('services', 'service_key')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->dropColumn('service_key');
            });
        }
    }
};
