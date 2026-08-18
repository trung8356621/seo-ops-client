<?php

declare(strict_types=1);

namespace Tests\Unit\Control;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

trait UsesClientControlSchema
{
    protected function bootClientControlSchema(): void
    {
        Config::set('database.core_connection', (string) config('database.default'));

        Schema::dropIfExists('client_control_commands');
        Schema::dropIfExists('client_control_state');
        Schema::dropIfExists('services');

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('addon_namespace');
            $table->string('db_connection')->default('mysql');
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('client_control_state', function (Blueprint $table): void {
            $table->id();
            $table->uuid('installation_id')->nullable()->unique();
            $table->string('control_server_url')->nullable();
            $table->text('installation_secret')->nullable();
            $table->string('status', 32)->default('unregistered');
            $table->unsignedBigInteger('services_revision')->nullable();
            $table->string('client_version', 64)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('last_command_at')->nullable();
            $table->uuid('last_command_id')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_control_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('command_id')->unique();
            $table->string('command', 64);
            $table->string('payload_hash', 64);
            $table->string('status', 32)->default('received');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }
}
