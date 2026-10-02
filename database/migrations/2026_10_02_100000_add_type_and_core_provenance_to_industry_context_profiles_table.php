<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = (string) config('database.core_connection', 'mysql');

        Schema::connection($connection)->table('industry_context_profiles', function (Blueprint $table): void {
            $table->string('type', 16)->default('core')->after('name');
            $table->unsignedBigInteger('source_core_id')->nullable()->after('context_json');
            $table->char('source_core_hash', 64)->nullable()->after('source_core_id');

            $table->index(['key', 'type'], 'industry_context_profiles_key_type_index');
            $table->index(['key', 'type', 'is_active'], 'industry_context_profiles_key_type_active_index');
            $table->index('source_core_id', 'industry_context_profiles_source_core_id_index');
        });

        DB::connection($connection)->table('industry_context_profiles')->update(['type' => 'core']);
    }

    public function down(): void
    {
        Schema::connection((string) config('database.core_connection', 'mysql'))->table('industry_context_profiles', function (Blueprint $table): void {
            $table->dropIndex('industry_context_profiles_key_type_index');
            $table->dropIndex('industry_context_profiles_key_type_active_index');
            $table->dropIndex('industry_context_profiles_source_core_id_index');
            $table->dropColumn(['type', 'source_core_id', 'source_core_hash']);
        });
    }
};
