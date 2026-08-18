<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RuntimeLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local/dev only — log slow HTTP requests with lightweight DB stats.
 * No request body, tokens, or article content.
 */
final class LogLocalSlowHttpMiddleware
{
    private const SLOW_MS = 2000;

    private const VERY_SLOW_MS = 10000;

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $startedAt = hrtime(true);
        $queryCount = 0;
        $totalQueryMs = 0.0;
        $slowestQueryMs = 0.0;
        $slowestQuerySql = '';

        DB::listen(static function ($query) use (&$queryCount, &$totalQueryMs, &$slowestQueryMs, &$slowestQuerySql): void {
            $timeMs = (float) ($query->time ?? 0);
            $queryCount++;
            $totalQueryMs += $timeMs;
            if ($timeMs <= $slowestQueryMs) {
                return;
            }
            $slowestQueryMs = $timeMs;
            $sql = trim(preg_replace('/\s+/', ' ', (string) ($query->sql ?? '')) ?? '');
            $slowestQuerySql = strlen($sql) > 400 ? substr($sql, 0, 400).'…' : $sql;
        });

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        if ($durationMs < self::SLOW_MS) {
            return $response;
        }

        $severity = $durationMs >= self::VERY_SLOW_MS ? 'VERY_SLOW' : 'SLOW';
        $userId = null;
        try {
            $userId = auth()->id();
        } catch (\Throwable) {
            $userId = null;
        }

        $context = [
            'method' => $request->method(),
            'uri' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'memory_mb' => round(memory_get_usage(true) / 1_048_576, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 2),
            'user_id' => $userId,
            'route' => $request->route()?->getName(),
            'query_count' => $queryCount,
            'total_query_time_ms' => (int) round($totalQueryMs),
            'slowest_query_ms' => (int) round($slowestQueryMs),
            'slowest_query_sql' => $slowestQuerySql !== '' ? $slowestQuerySql : null,
        ];

        $line = date('c').' [SLOW_HTTP] '.$severity
            .' method='.$context['method']
            .' uri='.$context['uri']
            .' status='.$context['status']
            .' duration_ms='.$context['duration_ms']
            .' query_count='.$context['query_count']
            .' total_query_time_ms='.$context['total_query_time_ms']
            .' slowest_query_ms='.$context['slowest_query_ms']
            .' memory_mb='.$context['memory_mb']
            .' peak_memory_mb='.$context['peak_memory_mb']
            ."\n";
        try {
            @file_put_contents(storage_path('logs/slow-http.log'), $line, FILE_APPEND);
        } catch (\Throwable) {
            // ignore
        }

        RuntimeLogger::warning('[SLOW_HTTP] '.$severity, $context);

        return $response;
    }
}
