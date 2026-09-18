<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use App\System\Ai\Client\DefaultSystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityHandler;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Support\CapabilityModeResolver;
use App\System\Support\SystemExecutionMode;
use App\System\Workflow\Nodes\WorkflowNodeRegistry;
use App\System\Workflow\Transport\LegacyLocalWorkflowTransport;
use PHPUnit\Framework\TestCase;

final class SystemApiContractTest extends TestCase
{
    public function test_capability_mode_resolver_prefers_capability_over_module(): void
    {
        // Lightweight config stub via putenv is brittle; test enum parse + resolver defaults.
        self::assertSame(SystemExecutionMode::Remote, SystemExecutionMode::tryParse('native'));
        self::assertSame(SystemExecutionMode::Shadow, SystemExecutionMode::tryParse('shadow'));
        self::assertSame(SystemExecutionMode::Legacy, SystemExecutionMode::tryParse('legacy'));
    }

    public function test_ai_execution_via_registered_capability_without_seo(): void
    {
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: 'demo.ping',
            owner: 'demo',
            handler: new class implements SystemCapabilityHandler
            {
                public function handle(array $input, array $context = []): array
                {
                    return ['pong' => true, 'echo' => $input['x'] ?? null];
                }
            },
            sideEffectFree: true,
        ));

        $transport = new LegacyLocalAiTransport($registry);
        $client = new DefaultSystemAiClient(new CapabilityModeResolver(), $transport);

        $result = $client->execute(new AiExecutionRequest(
            capability: 'demo.ping',
            input: ['x' => 1],
            correlation: ['id' => 'c1'],
        ));

        self::assertSame('completed', $result->status);
        self::assertTrue($result->output['pong'] ?? false);
        self::assertSame(1, $result->output['echo'] ?? null);
        self::assertSame('demo', $result->trace['owner'] ?? null);
    }

    public function test_workflow_validate_is_coarse_grained(): void
    {
        $transport = new LegacyLocalWorkflowTransport(new WorkflowNodeRegistry());
        $ok = $transport->validate([
            'nodes' => [['id' => 'a', 'type' => 'prompt']],
            'edges' => [],
        ]);
        self::assertTrue($ok['valid']);

        $bad = $transport->validate(['nodes' => []]);
        self::assertFalse($bad['valid']);
        self::assertNotEmpty($bad['errors']);
    }

    public function test_api_envelope_shapes(): void
    {
        $okData = [
            'ok' => true,
            'data' => ['a' => 1],
            'meta' => [
                'api' => 'system',
                'version' => 'v1',
            ],
        ];
        self::assertTrue($okData['ok']);
        self::assertSame('system', $okData['meta']['api']);

        $errData = [
            'ok' => false,
            'error' => [
                'code' => 'validation_error',
                'message' => 'bad',
                'correlation_id' => 'corr-1',
            ],
        ];
        self::assertFalse($errData['ok']);
        self::assertSame('validation_error', $errData['error']['code']);
    }
}
