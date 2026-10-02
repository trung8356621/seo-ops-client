<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection((string) config('database.core_connection', 'mysql'))->table('industry_context_profiles', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::connection((string) config('database.core_connection', 'mysql'))->table('industry_context_profiles', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
