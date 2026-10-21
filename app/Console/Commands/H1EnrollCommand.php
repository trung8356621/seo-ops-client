<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\H1\Enrollment\EnrollmentConsumer;
use App\Services\H1\Exceptions\H1EnrollmentException;
use Illuminate\Console\Command;

final class H1EnrollCommand extends Command
{
    protected $signature = 'h1:enroll {token : Single-use enrollment token (tok_...)}';

    protected $description = 'Consume an OPS-SERVER enrollment receipt and persist the Installation machine credential (H1).';

    public function handle(EnrollmentConsumer $consumer): int
    {
        try {
            $result = $consumer->enroll((string) $this->argument('token'));
        } catch (H1EnrollmentException $e) {
            $this->error('['.$e->errorCode.'] '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Enrollment '.($result['is_retry'] ? 'retry' : 'applied').'.');
        $this->line('installation_id='.$result['installation_id']);
        $this->line('credential_id='.$result['credential_id']);
        $this->line('revision='.$result['revision']);
        $this->warn('Live HTTP enrollment remains blocked until OPS-SERVER exposes POST /api/v1/enrollment/consume.');

        return self::SUCCESS;
    }
}
