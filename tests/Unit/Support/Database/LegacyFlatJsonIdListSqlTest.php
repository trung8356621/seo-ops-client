<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Database;

use App\Support\Database\LegacyFlatJsonIdListSql;
use PHPUnit\Framework\TestCase;

final class LegacyFlatJsonIdListSqlTest extends TestCase
{
    public function test_contains_keeps_find_in_set_flat_list_semantics(): void
    {
        $this->assertSame(
            'FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(`meta_value`, " ", ""), "[", ""), "]", "")) > 0',
            LegacyFlatJsonIdListSql::contains(),
        );
        $this->assertStringContainsString('FIND_IN_SET(list_wal.wp_post_id,', LegacyFlatJsonIdListSql::contains('list_wal.wp_post_id'));
    }
}
