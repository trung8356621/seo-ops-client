<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Driver-aware SQL fragments for calendar grouping / datetime compare.
 * Identifiers must be trusted column names, not user input.
 */
final class SqlExpressions
{
    public static function yearMonth(string $column, ?string $connection = null): string
    {
        return match (self::driver($connection)) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    public static function calendarDate(string $column, ?string $connection = null): string
    {
        return match (self::driver($connection)) {
            'pgsql' => "({$column})::date",
            default => "DATE({$column})",
        };
    }

    public static function asDateTime(string $expression, ?string $connection = null): string
    {
        return match (self::driver($connection)) {
            'pgsql' => "({$expression})::timestamp",
            'sqlite' => "datetime({$expression})",
            default => "CAST({$expression} AS DATETIME)",
        };
    }

    public static function driver(?string $connection = null): string
    {
        return DB::connection($connection)->getDriverName();
    }
}
