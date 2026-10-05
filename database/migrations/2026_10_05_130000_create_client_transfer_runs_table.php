<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_transfer_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('type', 20); // 'export' | 'import'
            $table->string('status', 30)->default('pending'); // pending, running, completed, failed, rolling_back, rolled_back, rollback_failed
            $table->string('phase', 50)->default('queued');
            $table->string('current_dataset', 100)->nullable();
            $table->unsignedInteger('current_part')->nullable();
            $table->unsignedBigInteger('record_offset')->default(0);
            $table->unsignedBigInteger('total_records')->default(0);
            $table->unsignedBigInteger('processed_records')->default(0);
            $table->unsignedBigInteger('imported_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('blocked_count')->default(0);
            $table->unsignedBigInteger('warnings_count')->default(0);
            $table->unsignedBigInteger('missing_refs_count')->default(0);
            $table->text('artifact_path')->nullable();
            $table->text('retry_package_path')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_transfer_runs');
    }
};
