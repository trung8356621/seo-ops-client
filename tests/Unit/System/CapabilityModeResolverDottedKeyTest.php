<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use App\System\Support\CapabilityModeResolver;
use App\System\Support\SystemExecutionMode;
use Tests\TestCase;

/**
 * Capability keys contain dots — Laravel config() nests by default.
 * Production config/system.php stores flat map entries; nested config([...]) is used in tests.
 */
final class CapabilityModeResolverDottedKeyTest extends TestCase
{
    public function test_flat_capability_key_from_system_config_bag_resolves_remote(): void
    {
        config([
            'system.capabilities' => [
                'article.content.generate' => 'remote',
            ],
            'system.modules.ai' => 'legacy',
            'system.default_mode' => 'legacy',
        ]);

        $mode = (new CapabilityModeResolver())->resolve('article.content.generate', 'ai');

        self::assertSame(SystemExecutionMode::Remote, $mode);
    }

    public function test_nested_capability_config_set_still_resolves_for_tests(): void
    {
        config([
            'system.capabilities' => [],
            'system.modules.ai' => 'legacy',
            'system.default_mode' => 'legacy',
        ]);
        config(['system.capabilities.article.content.generate' => 'remote']);

        $mode = (new CapabilityModeResolver())->resolve('article.content.generate', 'ai');

        self::assertSame(SystemExecutionMode::Remote, $mode);
    }

    public function test_missing_capability_falls_back_to_module_mode(): void
    {
        config([
            'system.capabilities' => [],
            'system.modules.ai' => 'shadow',
            'system.default_mode' => 'legacy',
        ]);

        $mode = (new CapabilityModeResolver())->resolve('article.content.generate', 'ai');

        self::assertSame(SystemExecutionMode::Shadow, $mode);
    }
}
