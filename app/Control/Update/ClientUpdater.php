<?php

declare(strict_types=1);

namespace App\Control\Update;

interface ClientUpdater
{
    public function apply(ClientUpdateRequest $request): ClientUpdateResult;
}
