<?php

declare(strict_types=1);

namespace App\Control\Commands;

use App\Control\Commands\Handlers\ControlCommandHandler;
use App\Enums\ClientControlCommandStatus;

final class ControlCommandDispatcher
{
    /**
     * @param  array<string, ControlCommandHandler>  $handlers
     */
    public function __construct(
        private readonly array $handlers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $command, array $payload): ControlCommandResult
    {
        $handler = $this->handlers[$command] ?? null;
        if (! $handler instanceof ControlCommandHandler) {
            return new ControlCommandResult(
                status: ClientControlCommandStatus::Failed,
                error: 'unknown_command',
            );
        }

        return $handler->handle($payload);
    }
}
