<?php

declare(strict_types=1);

namespace App\Control\Commands\Handlers;

use App\Control\Commands\ControlCommandResult;
use App\Control\Update\ClientUpdateRequest;
use App\Control\Update\ClientUpdater;

final class ClientUpdateHandler implements ControlCommandHandler
{
    public function __construct(
        private readonly ClientUpdater $updater,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): ControlCommandResult
    {
        $request = ClientUpdateRequest::fromPayload($payload);
        $outcome = $this->updater->apply($request);

        if ($outcome->state === 'not_configured') {
            return ControlCommandResult::ignored([
                'state' => $outcome->state,
                'release' => $outcome->release,
                'message' => $outcome->message,
            ]);
        }

        return ControlCommandResult::completed([
            'state' => $outcome->state,
            'release' => $outcome->release,
            'message' => $outcome->message,
        ]);
    }
}
