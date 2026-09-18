<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

final class ArticleContentCapabilityConfigContractTest extends TestCase
{
    public function test_system_config_supports_article_content_capability_without_global_flag(): void
    {
        $path = dirname(__DIR__, 2).'/../config/system.php';
        $path = realpath($path) ?: $path;
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('SYSTEM_CAP_ARTICLE_CONTENT_GENERATE', $source);
        self::assertStringContainsString('article.content.generate', $source);
        self::assertStringContainsString('No global USE_NEW_AI flag', $source);
    }

    public function test_architecture_guard_still_blocks_system_importing_content_projects(): void
    {
        $root = realpath(dirname(__DIR__, 2).'/../app/System') ?: dirname(__DIR__, 2).'/../app/System';
        $executorBridge = dirname(__DIR__, 2).'/../addons/ai-prompt/src/PromptHooks/Runtime/PromptHookExplicitBindingExecutor.php';
        // Bridge lives in addon (allowed). System tree must stay clean.
        self::assertDirectoryExists($root);
        self::assertFileExists($executorBridge);
        $systemFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($systemFiles as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('Omnichannel\\Addons\\ContentProjects\\', $src, $file->getPathname());
            self::assertStringNotContainsString('Omnichannel\\Addons\\Seeding\\', $src, $file->getPathname());
        }
    }
}
