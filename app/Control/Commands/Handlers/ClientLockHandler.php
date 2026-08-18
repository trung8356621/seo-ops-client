<?php

declare(strict_types=1);

namespace App\Control\Commands\Handlers;

use App\Control\Commands\ControlCommandResult;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;

final class ClientLockHandler implements ControlCommandHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): ControlCommandResult
    {
        $state = ClientControlState::query()->orderBy('id')->first();
        if (! $state instanceof ClientControlState || $state->status === ClientControlStatus::Unregistered) {
            return ControlCommandResult::failed('unknown_installation');
        }

        if ($state->status === ClientControlStatus::Revoked) {
            return ControlCommandResult::failed('revoked');
        }

        $state->status = ClientControlStatus::Locked;
        $state->locked_at = now();
        $state->save();

        return ControlCommandResult::completed([
            'status' => ClientControlStatus::Locked->value,
            'locked_at' => $state->locked_at?->toIso8601String(),
        ]);
    }
}
