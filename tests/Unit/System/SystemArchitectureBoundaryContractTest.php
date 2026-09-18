<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * System plane must not import domain addon implementations.
 */
final class SystemArchitectureBoundaryContractTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN = [
        'Omnichannel\\Addons\\Seo\\',
        'Omnichannel\\Addons\\ContentProjects\\',
        'Omnichannel\\Addons\\Content\\',
        'Omnichannel\\Addons\\WordPress\\',
        'Omnichannel\\Addons\\Seeding\\',
        'Omnichannel\\Addons\\Social\\',
        'Omnichannel\\Addons\\Commerce\\',
        'Omnichannel\\Addons\\Media\\',
        'Omnichannel\\Addons\\SearchIntelligence\\',
        'Omnichannel\\Addons\\AiPrompt\\',
        'Omnichannel\\Addons\\Agent\\',
    ];

    public function test_system_tree_does_not_import_domain_addons(): void
    {
        $root = realpath(dirname(__DIR__, 2).'/../app/System')
            ?: dirname(__DIR__, 2).'/../app/System';
        self::assertDirectoryExists($root);

        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
            '/\.php$/i',
        );

        foreach ($iterator as $file) {
            $source = (string) file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $source,
                    $file->getPathname().' must not import '.$needle,
                );
                self::assertStringNotContainsString(
                    'use '.$needle,
                    $source,
                    $file->getPathname(),
                );
            }
        }
    }

    public function test_system_sdk_contracts_exist(): void
    {
        self::assertTrue(interface_exists(\App\System\Ai\Contracts\SystemAiClient::class));
        self::assertTrue(interface_exists(\App\System\Workflow\Contracts\SystemWorkflowClient::class));
        self::assertTrue(interface_exists(\App\System\Agent\Contracts\SystemAgentClient::class));
        self::assertTrue(interface_exists(\App\System\Capability\SystemCapabilityHandler::class));
        self::assertTrue(interface_exists(\App\System\Workflow\Nodes\WorkflowNodeHandler::class));
        self::assertTrue(interface_exists(\App\System\Agent\Contracts\AgentSkillContributor::class));
    }

    public function test_system_routes_file_declares_versioned_api(): void
    {
        $path = realpath(dirname(__DIR__, 2).'/../routes/system.php')
            ?: dirname(__DIR__, 2).'/../routes/system.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('ai/executions', $source);
        self::assertStringContainsString('workflow-runs', $source);
        self::assertStringContainsString('agent/conversations', $source);
        self::assertStringContainsString('agent/runs/{id}/stream', $source);
    }
}
