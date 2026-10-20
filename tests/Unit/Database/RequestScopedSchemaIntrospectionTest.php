<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Database\RequestScopedSchemaBuilder;
use App\Core\Database\SchemaIntrospectionRequestCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RequestScopedSchemaIntrospectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (app()->bound(SchemaIntrospectionRequestCache::class)) {
            app(SchemaIntrospectionRequestCache::class)->flush();
        }
    }

    public function test_repeated_has_column_reuses_one_column_listing_query(): void
    {
        Schema::dropIfExists('schema_memo_probe');
        Schema::create('schema_memo_probe', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        $queries = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $sql = strtolower($event->sql);
            if (
                str_contains($sql, 'schema_memo_probe')
                || str_contains($sql, 'pragma_table')
                || str_contains($sql, 'information_schema')
                || str_contains($sql, 'sqlite_master')
                || str_contains($sql, 'sqlite_schema')
            ) {
                $queries[] = $event->sql;
            }
        });

        self::assertTrue(Schema::hasColumn('schema_memo_probe', 'name'));
        self::assertTrue(Schema::hasColumn('schema_memo_probe', 'id'));
        self::assertFalse(Schema::hasColumn('schema_memo_probe', 'missing_col'));
        for ($i = 0; $i < 20; $i++) {
            self::assertTrue(Schema::hasColumn('schema_memo_probe', 'name'));
            self::assertTrue(Schema::hasTable('schema_memo_probe'));
        }

        // Without memo: each hasColumn → getColumns SQL; with memo: one listing + one hasTable.
        self::assertLessThanOrEqual(
            4,
            count($queries),
            'Expected request-scoped schema memo to collapse repeated introspection. SQLs: '.implode(' | ', $queries),
        );

        $cache = app(SchemaIntrospectionRequestCache::class);
        self::assertSame(1, $cache->columnListingHits());
        self::assertGreaterThanOrEqual(1, $cache->tableHits());

        Schema::dropIfExists('schema_memo_probe');
        RequestScopedSchemaBuilder::forgetTable(DB::connection(), 'schema_memo_probe');
    }

    /**
     * Production editor reported ~240 column + ~174 table introspection queries.
     * Same-table repeats must collapse to one listing + one exists check.
     */
    public function test_production_scale_schema_storm_collapses_to_two_queries(): void
    {
        Schema::dropIfExists('schema_storm_probe');
        Schema::create('schema_storm_probe', function ($table): void {
            $table->id();
            $table->string('body')->nullable();
        });
        app(SchemaIntrospectionRequestCache::class)->flush();

        $introspection = 0;
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$introspection): void {
            $sql = strtolower($event->sql);
            if (
                str_contains($sql, 'schema_storm_probe')
                || str_contains($sql, 'information_schema')
                || str_contains($sql, 'pragma_table')
                || str_contains($sql, 'sqlite_master')
                || str_contains($sql, 'sqlite_schema')
            ) {
                $introspection++;
            }
        });

        $t0 = hrtime(true);
        $mem0 = memory_get_peak_usage(true);
        for ($i = 0; $i < 240; $i++) {
            Schema::hasColumn('schema_storm_probe', 'body');
        }
        for ($i = 0; $i < 174; $i++) {
            Schema::hasTable('schema_storm_probe');
        }
        $elapsedMs = (hrtime(true) - $t0) / 1e6;
        $memPeak = memory_get_peak_usage(true);

        // Naive Laravel cost for this pattern: 240 + 174 = 414 introspection queries.
        $naive = 240 + 174;
        self::assertLessThanOrEqual(3, $introspection);
        self::assertSame(1, app(SchemaIntrospectionRequestCache::class)->columnListingHits());
        self::assertSame(1, app(SchemaIntrospectionRequestCache::class)->tableHits());

        // Keep numbers in assertion message for before/after reports.
        self::assertTrue(
            $elapsedMs >= 0 && $memPeak >= $mem0,
            sprintf(
                'schema storm memo: queries %d→%d (saved %d), %.2fms, peak_mem=%d',
                $naive,
                $introspection,
                $naive - $introspection,
                $elapsedMs,
                $memPeak,
            ),
        );

        Schema::dropIfExists('schema_storm_probe');
    }

    public function test_schema_builder_registration_is_wired(): void
    {
        $path = base_path('app/Providers/AppServiceProvider.php');
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('RequestScopedSchemaBuilder::register()', $source);
        self::assertTrue(class_exists(RequestScopedSchemaBuilder::class));
        self::assertTrue(app()->bound(SchemaIntrospectionRequestCache::class));
    }
}
