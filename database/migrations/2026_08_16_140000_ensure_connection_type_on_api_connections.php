<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair: 2026_07_12_120000 may be recorded in `migrations` while the column is absent.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('api_connections', 'connection_type')) {
            Schema::connection($this->connection)->table('api_connections', function (Blueprint $table): void {
                $table->string('connection_type', 8)->default('ai')->after('provider');
                $table->index('connection_type');
            });
        }

        $aiProviders = [
            ApiConnectionProviders::GEMINI,
            ApiConnectionProviders::CLAUDE,
            ApiConnectionProviders::DEEPSEEK,
        ];

        DB::connection($this->connection)
            ->table('api_connections')
            ->whereIn('provider', $aiProviders)
            ->update(['connection_type' => 'ai']);

        DB::connection($this->connection)
            ->table('api_connections')
            ->whereNotIn('provider', $aiProviders)
            ->update(['connection_type' => 'seo']);
    }

    public function down(): void
    {
        // Do not drop: original 2026_07_12 migration still owns the column lifecycle.
    }
};
