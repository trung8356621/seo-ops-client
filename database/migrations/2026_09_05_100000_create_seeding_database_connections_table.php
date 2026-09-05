<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastructure-only Seeding DB credentials (Core mysql / omi_client).
 * Not a Seeding business table — omi_seeding remains schema-empty this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seeding_database_connections')) {
            return;
        }

        Schema::create('seeding_database_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 16)->default('manual');
            $table->string('host')->nullable();
            $table->string('port', 16)->nullable();
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seeding_database_connections');
    }
};
