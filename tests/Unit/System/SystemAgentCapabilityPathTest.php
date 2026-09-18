<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use App\System\Agent\Dto\AgentMessageRequest;
use App\System\Agent\Runtime\CapabilityBasedAgentRuntime;
use App\System\Agent\Skills\SystemAgentSkillRegistry;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityHandler;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Agent\Contracts\AgentSkillContributor;
use PHPUnit\Framework\TestCase;

final class SystemAgentCapabilityPathTest extends TestCase
{
    public function test_agent_runtime_does_not_require_content_project(): void
    {
        $caps = new SystemCapabilityRegistry();
        $caps->register(new SystemCapabilityDefinition(
            key: 'agent.echo',
            owner: 'agent',
            handler: new class implements SystemCapabilityHandler
            {
                public function handle(array $input, array $context = []): array
                {
                    return [
                        'echo' => (string) ($input['message'] ?? ''),
                        'content_project_required' => false,
                    ];
                }
            },
            sideEffectFree: true,
        ));

        $skills = new SystemAgentSkillRegistry();
        $skills->registerContributor(new class implements AgentSkillContributor
        {
            public function ownerSlug(): string
            {
                return 'agent';
            }

            public function skills(): array
            {
                return [[
                    'key' => 'agent.echo',
                    'capability' => 'agent.echo',
                ]];
            }
        });

        $runtime = new CapabilityBasedAgentRuntime($skills, $caps);
        $result = $runtime->sendMessage(new AgentMessageRequest(
            conversationId: null,
            message: 'hello',
            context: ['skill' => 'agent.echo'],
        ));

        self::assertSame('completed', $result->status);
        self::assertSame('hello', $result->output['echo'] ?? null);
        self::assertFalse($result->output['content_project_required'] ?? true);
        self::assertSame('capability_based', $result->meta['runtime'] ?? null);

        $events = array_column($result->events, 'event');
        self::assertContains('message.accepted', $events);
        self::assertContains('planning.started', $events);
        self::assertContains('tool.completed', $events);
        self::assertContains('assistant.completed', $events);
    }
}
