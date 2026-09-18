<?php

declare(strict_types=1);

namespace App\System\Capability;

final class SystemCapabilityDefinition
{
    /**
     * @param  class-string<SystemCapabilityHandler>|SystemCapabilityHandler  $handler
     */
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly string|SystemCapabilityHandler $handler,
        public readonly bool $sideEffectFree = false,
    ) {}
}
