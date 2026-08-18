<?php

declare(strict_types=1);

namespace App\Control\Commands\Handlers;

use App\Control\Commands\ControlCommandResult;

interface ControlCommandHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): ControlCommandResult;
}
