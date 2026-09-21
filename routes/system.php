<?php

declare(strict_types=1);

use App\System\Agent\Api\AgentConversationController;
use App\System\Ai\Api\AiExecutionController;
use App\System\Http\Middleware\SystemApiTokenAuth;
use App\System\Workflow\Api\WorkflowController;
use Illuminate\Support\Facades\Route;

/*
| System API v1 — coarse-grained AI / Workflow / Agent boundaries.
|
| AI + Workflow service executions: Bearer token (SYSTEM_API_TOKEN).
| Agent surfaces keep web+auth for browser/internal debug.
*/

Route::middleware([SystemApiTokenAuth::class])->group(function (): void {
    Route::post('/ai/executions', [AiExecutionController::class, 'store'])
        ->name('system.v1.ai.executions.store');
    Route::get('/ai/executions/{id}', [AiExecutionController::class, 'show'])
        ->name('system.v1.ai.executions.show');

    Route::post('/workflows/validate', [WorkflowController::class, 'validate'])
        ->name('system.v1.workflows.validate');
    Route::post('/workflow-runs', [WorkflowController::class, 'storeRun'])
        ->name('system.v1.workflow-runs.store');
    Route::get('/workflow-runs/{id}', [WorkflowController::class, 'showRun'])
        ->name('system.v1.workflow-runs.show');
    Route::post('/workflow-runs/{id}/cancel', [WorkflowController::class, 'cancel'])
        ->name('system.v1.workflow-runs.cancel');
    Route::post('/workflow-runs/{id}/retry', [WorkflowController::class, 'retry'])
        ->name('system.v1.workflow-runs.retry');
    Route::post('/workflow-runs/{id}/resume', [WorkflowController::class, 'resume'])
        ->name('system.v1.workflow-runs.resume');
});

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post('/agent/conversations', [AgentConversationController::class, 'storeConversation'])
        ->name('system.v1.agent.conversations.store');
    Route::post('/agent/conversations/{id}/messages', [AgentConversationController::class, 'storeMessage'])
        ->name('system.v1.agent.conversations.messages.store');
    Route::get('/agent/runs/{id}', [AgentConversationController::class, 'showRun'])
        ->name('system.v1.agent.runs.show');
    Route::get('/agent/runs/{id}/stream', [AgentConversationController::class, 'streamRun'])
        ->name('system.v1.agent.runs.stream');
});
