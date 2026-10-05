<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ClientTransfer\ClientTransferImporter;
use Illuminate\Console\Command;

final class ImportSeoPackageCommand extends Command
{
    protected $signature = 'client:import-seo
        {package : Path to transfer ZIP package}
        {--force : Force import even if target database contains records}';

    protected $description = 'Import portable SEO data package into fresh client';

    public function handle(): int
    {
        $packagePath = trim((string) $this->argument('package'));
        if (! file_exists($packagePath)) {
            $this->error("Package file not found: [{$packagePath}]");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $importer = new ClientTransferImporter();

        $this->info("Inspecting package [{$packagePath}]...");
        try {
            $inspection = $importer->inspect($packagePath);
            $manifest = $inspection['manifest'];

            $this->line("Format: {$manifest->format} (version {$manifest->formatVersion})");
            $this->line("Source exported at: {$manifest->exportedAt}");
            $this->line('Target empty: ' . ($inspection['target_empty'] ? 'yes' : 'NO'));

            if (! $inspection['target_empty'] && ! $force) {
                $this->error('Target SEO database contains existing records. Pass --force to override or clean database.');

                return self::FAILURE;
            }

            if (! $this->confirm('Proceed with SEO package import?', true)) {
                $this->warn('Import cancelled.');

                return self::SUCCESS;
            }

            $this->info('Importing SEO data...');
            $result = $importer->import($packagePath, $force);

            $this->info("Import completed (run ID: {$result['run_id']})");
            $this->line("  Imported:     {$result['total_imported']}");
            $this->line("  Failed:       {$result['total_failed']}");
            $this->line("  Blocked:      {$result['total_blocked']}");
            $this->line("  Warnings:     {$result['total_warnings']}");
            $this->line("  Missing refs: {$result['total_missing_refs']}");

            $table = [];
            foreach ($result['dataset_stats'] as $dKey => $stats) {
                $table[] = [
                    'Dataset' => $dKey,
                    'Exported' => $stats['export_count'],
                    'Imported' => $stats['imported'],
                    'Failed' => $stats['failed'],
                    'Blocked' => $stats['blocked'],
                    'Warnings' => $stats['warnings'],
                ];
            }
            $this->table(['Dataset', 'Exported', 'Imported', 'Failed', 'Blocked', 'Warnings'], $table);

            if (! empty($result['retry_package_path'])) {
                $this->warn("Quarantine retry package created at: {$result['retry_package_path']}");
            }

            return $result['total_failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
