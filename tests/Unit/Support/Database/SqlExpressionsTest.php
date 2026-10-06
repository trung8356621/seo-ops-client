<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Database;

use App\Support\Database\SqlExpressions;
use Tests\TestCase;

final class SqlExpressionsTest extends TestCase
{
    public function test_year_month_uses_sqlite_strftime_on_phpunit(): void
    {
        $this->assertSame("strftime('%Y-%m', archived_at)", SqlExpressions::yearMonth('archived_at'));
    }

    public function test_calendar_date_uses_date_on_sqlite(): void
    {
        $this->assertSame('DATE(created_at)', SqlExpressions::calendarDate('created_at'));
    }

    public function test_as_datetime_uses_datetime_on_sqlite(): void
    {
        $this->assertSame('datetime(seo_media_meta.meta_value)', SqlExpressions::asDateTime('seo_media_meta.meta_value'));
    }
}
