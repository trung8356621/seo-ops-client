<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection((string) config('database.core_connection', 'mysql'))
            ->create('industry_context_profiles', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->index();
                $table->string('name');
                $table->string('schema_version', 16)->default('1.0');
                $table->json('context_json');
                $table->boolean('is_active')->default(false)->index();
                $table->timestamps();

                $table->index(['key', 'is_active']);
            });
    }

    public function down(): void
    {
        Schema::connection((string) config('database.core_connection', 'mysql'))
            ->dropIfExists('industry_context_profiles');
    }
};
