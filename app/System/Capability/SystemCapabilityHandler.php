<?php

declare(strict_types=1);

namespace App\System\Capability;

/**
 * Domain addons implement this contract; System resolves by capability key only.
 *
 * @phpstan-type CapabilityInput array<string, mixed>
 * @phpstan-type CapabilityContext array<string, mixed>
 * @phpstan-type CapabilityResult array<string, mixed>
 */
interface SystemCapabilityHandler
{
    /**
     * @param  CapabilityInput  $input
     * @param  CapabilityContext  $context
     * @return CapabilityResult
     */
    public function handle(array $input, array $context = []): array;
}
