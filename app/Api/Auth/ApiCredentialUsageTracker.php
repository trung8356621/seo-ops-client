<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Models\ServiceApiCredential;
use Illuminate\Support\Carbon;

/**
 * Throttled last_used_at touch to avoid DB write on every request.
 */
final class ApiCredentialUsageTracker
{
    public const TOUCH_INTERVAL_SECONDS = 300;

    public function touch(ServiceApiCredential $credential, ?Carbon $now = null): void
    {
        $now ??= now();
        $last = $credential->last_used_at;
        if ($last instanceof Carbon && $last->greaterThan($now->copy()->subSeconds(self::TOUCH_INTERVAL_SECONDS))) {
            return;
        }

        ServiceApiCredential::query()
            ->whereKey($credential->id)
            ->update(['last_used_at' => $now]);

        $credential->last_used_at = $now;
    }
}
