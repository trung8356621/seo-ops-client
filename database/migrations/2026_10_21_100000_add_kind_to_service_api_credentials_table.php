<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate user API Access keys from Service Gate managed credentials.
 * Existing rows default to kind=user (C-API-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_api_credentials')) {
            return;
        }

        if (! Schema::hasColumn('service_api_credentials', 'kind')) {
            Schema::table('service_api_credentials', function (Blueprint $table): void {
                $table->string('kind', 16)->default('user')->after('service_id');
                $table->index(['service_id', 'kind']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('service_api_credentials')) {
            return;
        }

        if (Schema::hasColumn('service_api_credentials', 'kind')) {
            Schema::table('service_api_credentials', function (Blueprint $table): void {
                $table->dropIndex(['service_id', 'kind']);
                $table->dropColumn('kind');
            });
        }
    }
};
