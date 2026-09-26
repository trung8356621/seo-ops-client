<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core-owned external/service API credentials.
 * Independent of services.service_key (ops-server provisioning secret).
 * Raw keys are never stored — only key_prefix + key_hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_api_credentials')) {
            return;
        }

        Schema::create('service_api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->string('name', 120);
            $table->string('key_prefix', 64);
            $table->string('key_hash', 128);
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('service_id');
            $table->index('key_prefix');
            $table->index('revoked_at');
            $table->index('expires_at');

            $table->foreign('service_id')
                ->references('id')
                ->on('services')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_api_credentials');
    }
};
