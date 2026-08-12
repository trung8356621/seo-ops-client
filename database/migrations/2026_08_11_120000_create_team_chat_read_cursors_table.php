<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_chat_read_cursors')) {
            Schema::create('team_chat_read_cursors', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('owner_id')->index();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('last_read_message_id')->default(0);
                $table->timestamps();

                $table->unique(['owner_id', 'user_id']);
                $table->index(['owner_id', 'last_read_message_id']);
            });
        }

        if (Schema::hasTable('team_messages') && ! $this->hasIndex('team_messages', 'team_messages_owner_id_id_index')) {
            Schema::table('team_messages', function (Blueprint $table): void {
                $table->index(['owner_id', 'id'], 'team_messages_owner_id_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('team_messages') && $this->hasIndex('team_messages', 'team_messages_owner_id_id_index')) {
            Schema::table('team_messages', function (Blueprint $table): void {
                $table->dropIndex('team_messages_owner_id_id_index');
            });
        }

        Schema::dropIfExists('team_chat_read_cursors');
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $rows = DB::select(
            'select 1 from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
            [$database, $table, $indexName],
        );

        return $rows !== [];
    }
};
