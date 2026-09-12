<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_connections', 'balance')) {
                $table->decimal('balance', 14, 4)->nullable()->after('metadata');
            }
            if (! Schema::hasColumn('api_connections', 'currency')) {
                $table->string('currency', 10)->nullable()->default('USD')->after('balance');
            }
            if (! Schema::hasColumn('api_connections', 'balance_status')) {
                $table->string('balance_status', 32)->nullable()->default('unknown')->after('currency');
            }
            if (! Schema::hasColumn('api_connections', 'balance_warning_threshold')) {
                $table->decimal('balance_warning_threshold', 14, 4)->nullable()->default(5.0000)->after('balance_status');
            }
            if (! Schema::hasColumn('api_connections', 'balance_checked_at')) {
                $table->timestamp('balance_checked_at')->nullable()->after('balance_warning_threshold');
            }
            if (! Schema::hasColumn('api_connections', 'balance_error')) {
                $table->text('balance_error')->nullable()->after('balance_checked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('api_connections', function (Blueprint $table): void {
            $columns = [
                'balance',
                'currency',
                'balance_status',
                'balance_warning_threshold',
                'balance_checked_at',
                'balance_error',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('api_connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
