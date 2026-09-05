<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core infrastructure: one optional DB connection per Service (UNIQUE service_id).
 * Not a product resource — subordinate to services.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_database_connections')) {
            return;
        }

        Schema::create('service_database_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->string('type', 16)->default('manual');
            $table->string('driver', 32)->default('mysql');
            $table->string('host')->nullable();
            $table->string('port', 16)->nullable();
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('service_id');
            $table->foreign('service_id')
                ->references('id')
                ->on('services')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_database_connections');
    }
};
