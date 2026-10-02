<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class IndustryContextProfileMigrationTest extends TestCase
{
    public function test_existing_rows_are_backfilled_as_core_with_nullable_provenance(): void
    {
        Config::set('database.core_connection', 'sqlite');
        Schema::connection('sqlite')->dropIfExists('industry_context_profiles');
        Schema::connection('sqlite')->create('industry_context_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->string('name');
            $table->string('schema_version')->default('1.0');
            $table->json('context_json');
            $table->boolean('is_active')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        DB::connection('sqlite')->table('industry_context_profiles')->insert([
            'key' => 'bags', 'name' => 'Bags', 'context_json' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_02_100000_add_type_and_core_provenance_to_industry_context_profiles_table.php');
        $migration->up();

        $row = DB::connection('sqlite')->table('industry_context_profiles')->first();
        self::assertSame('core', $row->type);
        self::assertNull($row->source_core_id);
        self::assertNull($row->source_core_hash);
    }
}
