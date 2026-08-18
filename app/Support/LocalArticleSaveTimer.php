<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Operations\CorrelationId;
use Throwable;

/**
 * Local/dev only — time article save steps. No-op outside local. Never logs content/secrets.
 */
final class LocalArticleSaveTimer
{
    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function measure(int $articleId, string $step, callable $callback): mixed
    {
        if (! self::enabled()) {
            return $callback();
        }

        $startedAt = hrtime(true);
        try {
            return $callback();
        } finally {
            self::log($articleId, $step, (int) round((hrtime(true) - $startedAt) / 1_000_000));
        }
    }

    public static function log(int $articleId, string $step, int $durationMs): void
    {
        if (! self::enabled()) {
            return;
        }

        $correlationId = '';
        try {
            $correlationId = CorrelationId::get() ?? '';
        } catch (Throwable) {
            $correlationId = '';
        }

        $line = date('c')
            .' [ARTICLE_SAVE_TIMING]'
            .' article_id='.$articleId
            .' step='.$step
            .' duration_ms='.$durationMs
            .($correlationId !== '' ? ' correlation_id='.$correlationId : '')
            ."\n";

        try {
            @file_put_contents(storage_path('logs/article-save-timing.log'), $line, FILE_APPEND);
        } catch (Throwable) {
            // ignore
        }

        try {
            RuntimeLogger::warning('[ARTICLE_SAVE_TIMING]', [
                'article_id' => $articleId,
                'step' => $step,
                'duration_ms' => $durationMs,
                'correlation_id' => $correlationId !== '' ? $correlationId : null,
            ]);
        } catch (Throwable) {
            // ignore
        }
    }

    public static function enabled(): bool
    {
        try {
            return function_exists('app') && app()->environment('local');
        } catch (Throwable) {
            return false;
        }
    }
}
