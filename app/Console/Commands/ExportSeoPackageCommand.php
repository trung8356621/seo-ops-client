<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ClientTransfer\ClientTransferExporter;
use Illuminate\Console\Command;

final class ExportSeoPackageCommand extends Command
{
    protected $signature = 'client:export-seo
        {--path= : Target ZIP file path}';

    protected $description = 'Export portable SEO data package for client transfer';

    public function handle(): int
    {
        $this->info('Starting SEO portable data export...');

        $customPath = $this->option('path') ? (string) $this->option('path') : null;

        $exporter = new ClientTransferExporter();

        try {
            $result = $exporter->export($customPath);

            $this->info('SEO package successfully exported!');
            $this->line("Package: {$result['destination_path']}");
            $this->line('Size: ' . number_format($result['file_size']) . ' bytes');
            $this->line("Exported at: {$result['exported_at']}");

            $table = [];
            foreach ($result['counts'] as $dataset => $count) {
                $table[] = ['Dataset' => $dataset, 'Records' => number_format($count)];
            }
            $this->table(['Dataset', 'Records'], $table);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('SEO export failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
