<?php

declare(strict_types=1);

namespace App\Control\Update;

/**
 * Placeholder until ops-server sends an exact release and source replacement exists.
 */
final class NotConfiguredClientUpdater implements ClientUpdater
{
    public function apply(ClientUpdateRequest $request): ClientUpdateResult
    {
        return ClientUpdateResult::notConfigured($request->release ?? $request->version);
    }
}
