<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Reconcile\LicenseReconcileService;
use Illuminate\Console\Command;

final class H1ReconcileCommand extends Command
{
    protected $signature = 'h1:reconcile';

    protected $description = 'Run a signed seo-ops-license/1 reconcile against OPS-SERVER (manual; schedule disabled).';

    public function handle(LicenseReconcileService $reconcile): int
    {
        try {
            $result = $reconcile->reconcile();
        } catch (H1TransportException $e) {
            $this->error('['.$e->errorCode.'] '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['offline']) {
            $this->warn('OPS-SERVER offline — using last verified entitlement snapshot.');

            return self::SUCCESS;
        }

        $this->info('commands_applied='.$result['commands_applied']);
        $this->info('acks='.count($result['acks']));

        return self::SUCCESS;
    }
}
