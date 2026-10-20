<?php

declare(strict_types=1);

namespace App\Core\Performance;

use Illuminate\Contracts\Cache\Lock;

/**
 * Held heavy-slot lock. Always release in finally / failed hooks.
 */
final class HeavyWorkloadPermit
{
    public function __construct(
        private readonly Lock $lock,
        public readonly int $slot,
    ) {}

    public function release(): void
    {
        try {
            $this->lock->release();
        } catch (\Throwable) {
            try {
                if (method_exists($this->lock, 'isOwnedByCurrentProcess') && ! $this->lock->isOwnedByCurrentProcess()) {
                    return;
                }
                $this->lock->forceRelease();
            } catch (\Throwable) {
            }
        }
    }
}
