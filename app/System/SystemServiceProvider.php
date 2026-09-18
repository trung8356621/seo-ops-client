<?php

declare(strict_types=1);

namespace App\System;

use App\System\Agent\Client\DefaultSystemAgentClient;
use App\System\Agent\Contracts\AgentRuntimePort;
use App\System\Agent\Contracts\SystemAgentClient;
use App\System\Agent\Runtime\CapabilityBasedAgentRuntime;
use App\System\Agent\Skills\SystemAgentSkillRegistry;
use App\System\Ai\Client\DefaultSystemAiClient;
use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Ai\Contracts\SystemAiClient;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Ai\Transport\RemoteHttpAiTransport;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Support\CapabilityModeResolver;
use App\System\Workflow\Client\DefaultSystemWorkflowClient;
use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Contracts\WorkflowRuntimePort;
use App\System\Workflow\Nodes\WorkflowNodeRegistry;
use App\System\Workflow\Transport\LegacyLocalWorkflowTransport;
use Illuminate\Support\ServiceProvider;

/**
 * System AI / Workflow / Agent — API-first platform plane.
 * Protocol/runtime only: no SEO/Content/Seeding domain imports.
 */
final class SystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/system.php', 'system');

        $this->app->singleton(CapabilityModeResolver::class);
        $this->app->singleton(SystemCapabilityRegistry::class);
        $this->app->singleton(WorkflowNodeRegistry::class);
        $this->app->singleton(SystemAgentSkillRegistry::class);
        $this->app->singleton(CapabilityBasedAgentRuntime::class);

        $this->app->singleton(LegacyLocalAiTransport::class, function ($app): LegacyLocalAiTransport {
            return new LegacyLocalAiTransport(
                capabilities: $app->make(SystemCapabilityRegistry::class),
                textPort: $app->bound(AiTextExecutionPort::class)
                    ? $app->make(AiTextExecutionPort::class)
                    : null,
            );
        });

        $remoteBase = trim((string) config('system.http.base_url', ''));
        if ($remoteBase !== '') {
            $this->app->singleton(RemoteHttpAiTransport::class, function () use ($remoteBase): RemoteHttpAiTransport {
                return new RemoteHttpAiTransport(
                    baseUrl: $remoteBase,
                    serviceToken: (string) config('system.http.service_token', ''),
                    timeoutSeconds: (int) config('system.http.timeout_seconds', 120),
                );
            });
        }

        $this->app->singleton(SystemAiClient::class, function ($app): SystemAiClient {
            return new DefaultSystemAiClient(
                modes: $app->make(CapabilityModeResolver::class),
                local: $app->make(LegacyLocalAiTransport::class),
                remote: $app->bound(RemoteHttpAiTransport::class)
                    ? $app->make(RemoteHttpAiTransport::class)
                    : null,
                capabilities: $app->make(SystemCapabilityRegistry::class),
            );
        });

        $this->app->singleton(LegacyLocalWorkflowTransport::class, function ($app): LegacyLocalWorkflowTransport {
            return new LegacyLocalWorkflowTransport(
                nodes: $app->make(WorkflowNodeRegistry::class),
                runtime: $app->bound(WorkflowRuntimePort::class)
                    ? $app->make(WorkflowRuntimePort::class)
                    : null,
            );
        });

        $this->app->singleton(SystemWorkflowClient::class, function ($app): SystemWorkflowClient {
            return new DefaultSystemWorkflowClient(
                modes: $app->make(CapabilityModeResolver::class),
                local: $app->make(LegacyLocalWorkflowTransport::class),
            );
        });

        $this->app->singleton(SystemAgentClient::class, function ($app): SystemAgentClient {
            return new DefaultSystemAgentClient(
                modes: $app->make(CapabilityModeResolver::class),
                native: $app->make(CapabilityBasedAgentRuntime::class),
                legacy: $app->bound(AgentRuntimePort::class)
                    ? $app->make(AgentRuntimePort::class)
                    : null,
            );
        });
    }

    public function boot(): void
    {
        // Routes registered from bootstrap/app.php then: callback.
    }
}
