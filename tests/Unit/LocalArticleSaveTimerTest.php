<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LocalArticleSaveTimer;
use PHPUnit\Framework\TestCase;

final class LocalArticleSaveTimerTest extends TestCase
{
    public function test_timer_is_local_only_and_does_not_log_content(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Support/LocalArticleSaveTimer.php',
        );

        self::assertStringContainsString("app()->environment('local')", $source);
        self::assertStringContainsString('[ARTICLE_SAVE_TIMING]', $source);
        self::assertStringContainsString('article-save-timing.log', $source);
        self::assertStringNotContainsString('getContent()', $source);
        self::assertStringNotContainsString('password', $source);
    }

    public function test_measure_invokes_callback_when_not_local(): void
    {
        $called = false;
        $result = LocalArticleSaveTimer::measure(1, 'test.step', static function () use (&$called): string {
            $called = true;

            return 'ok';
        });

        self::assertTrue($called);
        self::assertSame('ok', $result);
    }
}
