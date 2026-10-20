<?php

declare(strict_types=1);

namespace App\Core\Performance;

use Illuminate\Support\Facades\Cache;

/**
 * Distinct authenticated users recently active (TTL window).
 * Multiple tabs of one account share one user id → count as one.
 *
 * Approximation: updated on throttled authenticated web hits + explicit visibility pings.
 * Not a full presence mesh / WebSocket roster.
 */
final class ActiveUserPresence
{
    public const MAP_KEY = 'perf:active_users:map';

    public const WRITE_LOCK = 'perf:active_users:write';

    /** Seconds a user remains "active" after last touch. */
    public const TTL_SECONDS = 180;

    /** Per-user throttle so ordinary page traffic does not lock-spam. */
    public const TOUCH_THROTTLE_SECONDS = 45;

    public function touch(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $throttleKey = 'perf:active_users:touch:'.$userId;
        try {
            if (! Cache::add($throttleKey, 1, self::TOUCH_THROTTLE_SECONDS)) {
                return;
            }
        } catch (\Throwable) {
            // continue without throttle when cache unavailable
        }

        $this->writeTouch($userId);
    }

    /**
     * Force-update (e.g. tab became visible) — bypasses short throttle.
     */
    public function ping(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $this->writeTouch($userId);
    }

    public function count(): int
    {
        $map = $this->readMap();
        $now = time();
        $n = 0;
        foreach ($map as $ts) {
            if ($now - (int) $ts <= self::TTL_SECONDS) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array<int, int> userId => unix timestamp
     */
    public function readMap(): array
    {
        try {
            $raw = Cache::get(self::MAP_KEY, []);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $userId => $ts) {
            $id = (int) $userId;
            if ($id > 0) {
                $out[$id] = (int) $ts;
            }
        }

        return $out;
    }

    public function flush(): void
    {
        try {
            Cache::forget(self::MAP_KEY);
        } catch (\Throwable) {
        }
    }

    private function writeTouch(int $userId): void
    {
        try {
            $lock = Cache::lock(self::WRITE_LOCK, 5);
            $lock->block(3, function () use ($userId): void {
                $now = time();
                $map = $this->readMap();
                $map[$userId] = $now;
                foreach ($map as $id => $ts) {
                    if ($now - (int) $ts > self::TTL_SECONDS) {
                        unset($map[$id]);
                    }
                }
                Cache::put(self::MAP_KEY, $map, self::TTL_SECONDS + 120);
            });
        } catch (\Throwable) {
            // Best-effort — never break request path for presence.
        }
    }
}
