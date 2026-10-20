<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Queue middleware: optional-background policy + heavy concurrency permit.
 * Waiting jobs call release($delay) — they do not occupy PHP-FPM or spin-retry.
 */
final class HeavyWorkloadMiddleware
{
    public const RELEASE_SECONDS = 15;

    public const BACKGROUND_RELEASE_SECONDS = 25;

    public function __construct(
        private readonly HeavyWorkloadGate $gate = new HeavyWorkloadGate(),
        private readonly BackgroundHeavyPolicy $background = new BackgroundHeavyPolicy(),
    ) {}

    public function handle(object $job, callable $next): mixed
    {
        if ($job instanceof OptionalBackgroundHeavy && $job->isOptionalBackgroundHeavy()) {
            if (! $this->background->allowsOptionalBackground()) {
                $this->releaseJob($job, self::BACKGROUND_RELEASE_SECONDS);

                return null;
            }
        }

        $ttl = 600;
        if (isset($job->timeout) && is_numeric($job->timeout)) {
            $ttl = max(30, (int) $job->timeout + 60);
        }

        $permit = $this->gate->tryAcquire($ttl);
        if ($permit === null) {
            $this->releaseJob($job, self::RELEASE_SECONDS);

            return null;
        }

        try {
            return $next($job);
        } finally {
            $permit->release();
        }
    }

    private function releaseJob(object $job, int $seconds): void
    {
        if (method_exists($job, 'release')) {
            $job->release($seconds);
        }
    }
}
