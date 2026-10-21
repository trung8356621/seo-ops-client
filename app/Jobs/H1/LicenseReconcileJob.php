<?php

declare(strict_types=1);

namespace App\Jobs\H1;

use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Reconcile\LicenseReconcileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background reconcile — must not share SEO article/publish queues when scheduled.
 * Schedule remains disabled until G3.
 */
final class LicenseReconcileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue((string) config('h1-license.queue', 'default'));
    }

    public function handle(LicenseReconcileService $reconcile): void
    {
        if (! (bool) config('h1-license.schedule_enabled', false)) {
            return;
        }

        try {
            $reconcile->reconcile();
        } catch (H1TransportException $e) {
            Log::warning('h1.reconcile_failed', [
                'code' => $e->errorCode,
                // never log secrets / tokens
            ]);
        }
    }
}
