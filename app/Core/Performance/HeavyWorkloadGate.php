<?php

declare(strict_types=1);

namespace App\Core\Performance;

use Illuminate\Support\Facades\Cache;

/**
 * Atomic admission for shared "heavy" Laravel queue work.
 * Does not change Supervisor worker counts — caps concurrent heavy execution via Cache locks.
 */
final class HeavyWorkloadGate
{
    public const SLOT_PREFIX = 'perf:heavy:slot:';

    public const SYSTEM_MAX_SLOTS = 4;

    public function __construct(
        private readonly PerformanceSettings $settings = new PerformanceSettings(),
    ) {}

    public function maxSlots(): int
    {
        return max(1, $this->settings->maxHeavyConcurrent());
    }

    /**
     * Try to acquire one heavy slot. Null = at capacity (caller should release job back to queue).
     */
    public function tryAcquire(int $ttlSeconds = 600): ?HeavyWorkloadPermit
    {
        $ttl = max(30, $ttlSeconds);
        $max = $this->maxSlots();

        $occupiedHigher = $this->countOccupiedHigherSlots($max, self::SYSTEM_MAX_SLOTS);
        $effectiveMax = max(0, $max - $occupiedHigher);

        for ($slot = 0; $slot < $effectiveMax; $slot++) {
            try {
                $lock = Cache::lock(self::SLOT_PREFIX.$slot, $ttl);
                if ($lock->get()) {
                    return new HeavyWorkloadPermit($lock, $slot);
                }
            } catch (\Throwable) {
                // try next slot / fail open below
            }
        }

        return null;
    }

    private function countOccupiedHigherSlots(int $fromSlot, int $systemMax): int
    {
        $count = 0;
        for ($slot = $fromSlot; $slot < $systemMax; $slot++) {
            try {
                $check = Cache::lock(self::SLOT_PREFIX.$slot, 1);
                if (! $check->get()) {
                    $count++;
                } else {
                    $check->release();
                }
            } catch (\Throwable) {
            }
        }

        return $count;
    }
}
