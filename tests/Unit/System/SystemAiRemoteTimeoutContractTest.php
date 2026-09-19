<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: remote System AI HTTP must outlive long-form writing.
 * Run #310 / article #8553 timed out at 120s while PR2108 still completed on server.
 */
final class SystemAiRemoteTimeoutContractTest extends TestCase
{
    #[Test]
    public function remote_http_timeout_covers_content_project_article_job(): void
    {
        $remoteTimeout = (int) config('system.http.timeout_seconds');
        $articleJobTimeout = (int) config(
            'seo-content-ai.content_project.article_job_timeout_seconds',
            900,
        );

        self::assertGreaterThanOrEqual(
            900,
            $remoteTimeout,
            'SYSTEM_API_TIMEOUT / system.http.timeout_seconds must cover long-form writing (run #310/#8553).',
        );
        self::assertGreaterThanOrEqual(
            $articleJobTimeout,
            $remoteTimeout,
            'Remote System AI timeout must be >= Content Project article job timeout.',
        );
    }
}
