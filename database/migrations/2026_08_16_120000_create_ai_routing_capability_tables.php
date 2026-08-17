<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable()->index();
            $table->unsignedBigInteger('api_connection_id')->nullable()->index();
            $table->string('model_key', 128)->index();
            $table->string('capability', 64);
            $table->string('source', 32)->default('built_in');
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['api_connection_id', 'model_key', 'capability', 'source'],
                'ai_model_caps_conn_model_cap_src_unique',
            );
        });

        Schema::create('ai_routing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('key', 64);
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('required_capabilities')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key'], 'ai_routing_profiles_user_key_unique');
        });

        Schema::create('ai_routing_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->string('profile_key', 64)->index();
            $table->unsignedBigInteger('api_connection_id')->index();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable()->index();
            $table->string('model_key', 128);
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'profile_key', 'priority'], 'ai_routing_targets_user_profile_priority');
            $table->unique(
                ['user_id', 'profile_key', 'api_connection_id', 'model_key'],
                'ai_routing_targets_user_profile_conn_model_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_routing_targets');
        Schema::dropIfExists('ai_routing_profiles');
        Schema::dropIfExists('ai_model_capabilities');
    }
};
