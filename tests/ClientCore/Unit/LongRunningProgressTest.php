<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Operations\LongRunningProgress;
use PHPUnit\Framework\TestCase;

final class LongRunningProgressTest extends TestCase
{
    public function test_percentage_clamps_and_skips_zero_total(): void
    {
        $half = LongRunningProgress::fromArray(['current' => 50, 'total' => 100]);
        self::assertSame(50, $half->percentage());

        $over = LongRunningProgress::fromArray(['current' => 200, 'total' => 100]);
        self::assertSame(100, $over->percentage());

        $zero = LongRunningProgress::fromArray(['current' => 10, 'total' => 0]);
        self::assertNull($zero->percentage());
    }

    public function test_merge_is_monotonic_on_current(): void
    {
        $first = LongRunningProgress::fromArray(['current' => 25, 'total' => 100, 'status' => 'running']);
        $second = $first->merge(['current' => 50], '2026-08-14T05:00:00+00:00');
        self::assertSame(50, $second->current);

        $stale = $second->merge(['current' => 40], '2026-08-14T05:00:01+00:00');
        self::assertSame(50, $stale->current);
        self::assertSame('2026-08-14T05:00:01+00:00', $stale->lastActivityAt);
    }

    public function test_merge_resets_current_when_phase_changes(): void
    {
        $catalog = LongRunningProgress::fromArray([
            'current' => 8078,
            'total' => 8078,
            'phase' => 'sync_url_catalog',
            'status' => 'running',
        ]);
        $keywords = $catalog->merge([
            'phase' => 'sync_provider_keywords',
            'current' => 65,
            'total' => 324,
        ], '2026-08-14T05:00:00+00:00');

        self::assertSame(65, $keywords->current);
        self::assertSame(324, $keywords->total);
        self::assertSame('sync_provider_keywords', $keywords->phase);
    }

    public function test_should_persist_throttles_small_deltas(): void
    {
        $a = LongRunningProgress::fromArray([
            'current' => 10,
            'total' => 100,
            'phase' => 'sync_url_catalog',
            'last_activity_at' => '2026-08-14T05:00:00+00:00',
        ]);
        $b = $a->merge(['current' => 12], '2026-08-14T05:00:01+00:00');
        self::assertFalse($b->shouldPersist($a, 20, 3));

        $c = $a->merge(['current' => 40], '2026-08-14T05:00:01+00:00');
        self::assertTrue($c->shouldPersist($a, 20, 3));

        $d = $a->merge(['current' => 11], '2026-08-14T05:00:05+00:00');
        self::assertTrue($d->shouldPersist($a, 20, 3));
    }

    public function test_stuck_uses_last_activity_threshold(): void
    {
        $fresh = LongRunningProgress::fromArray([
            'status' => 'running',
            'last_activity_at' => '2026-08-14T05:00:00+00:00',
        ]);
        self::assertFalse($fresh->isStuck(600, '2026-08-14T05:05:00+00:00'));
        self::assertTrue($fresh->isStuck(600, '2026-08-14T05:12:00+00:00'));

        $done = LongRunningProgress::fromArray([
            'status' => 'completed',
            'last_activity_at' => '2026-08-14T05:00:00+00:00',
        ]);
        self::assertFalse($done->isStuck(1, '2026-08-14T06:00:00+00:00'));
    }
}
