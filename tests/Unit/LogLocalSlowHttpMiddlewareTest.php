<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\LogLocalSlowHttpMiddleware;
use PHPUnit\Framework\TestCase;

final class LogLocalSlowHttpMiddlewareTest extends TestCase
{
    public function test_middleware_source_has_local_only_guard_and_slow_thresholds(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Middleware/LogLocalSlowHttpMiddleware.php',
        );

        self::assertStringContainsString("app()->environment('local')", $source);
        self::assertStringContainsString('[SLOW_HTTP]', $source);
        self::assertStringContainsString('slow-http.log', $source);
        self::assertStringContainsString('SLOW_MS = 2000', $source);
        self::assertStringContainsString('VERY_SLOW_MS = 10000', $source);
        self::assertStringContainsString('DB::listen', $source);
        self::assertStringContainsString('query_count', $source);
        self::assertStringNotContainsString('getContent()', $source);
    }

    public function test_middleware_is_registered_in_bootstrap(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2).'/bootstrap/app.php');

        self::assertStringContainsString(LogLocalSlowHttpMiddleware::class, $bootstrap);
    }
}
