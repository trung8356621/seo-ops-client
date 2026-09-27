<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PromptTask\PromptTaskCoreMigrationService;
use Illuminate\Console\Command;

final class MigratePromptTaskToCoreCommand extends Command
{
    protected $signature = 'prompt-task:migrate-to-core
        {--dry-run : Inventory + simulate copy, no write to target}
        {--execute : Copy records from source to target (idempotent)}
        {--verify : Compare counts + min/max PKs between source and target}';

    protected $description = 'Migrate canonical Prompt + Task tables from SEO DB (omi_seo_ai) to Core Client DB (mysql / omi_client).';

    public function handle(PromptTaskCoreMigrationService $service): int
    {
        $this->info('Prompt + Task DB Migration to Core');
        $this->line('source: ' . PromptTaskCoreMigrationService::sourceConnection());
        $this->line('target: ' . PromptTaskCoreMigrationService::targetConnection());
        $this->newLine();

        if ($this->option('verify')) {
            $report = $service->verify();
            $this->renderReport($report);
            return ($report['cutover_ready'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('execute')) {
            $report = $service->copy(execute: true);
            $this->renderReport($report);
            return ($report['cutover_ready'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        // Default: dry run
        $report = $service->dryRun();
        $this->renderReport($report);
        return self::SUCCESS;
    }

    private function renderReport(array $report): void
    {
        $this->line('Phase: ' . ($report['phase'] ?? 'unknown'));
        $this->table(
            ['Table', 'Status', 'Source Count', 'Target Count', 'Copied', 'Already Present', 'Conflicts'],
            array_map(function ($table, $info) {
                return [
                    $table,
                    $info['status'] ?? '-',
                    $info['source_count'] ?? '-',
                    $info['target_count'] ?? ($info['target_count_before'] ?? '-'),
                    $info['copied'] ?? 0,
                    $info['already_present'] ?? 0,
                    $info['conflicts'] ?? 0,
                ];
            }, array_keys($report['tables']), array_values($report['tables']))
        );

        foreach ($report['conflicts'] as $conflict) {
            $this->warn("CONFLICT: {$conflict['table']} #{$conflict['id']}");
        }

        foreach ($report['errors'] as $error) {
            $this->error($error);
        }

        $this->info('Cutover ready: ' . (($report['cutover_ready'] ?? false) ? 'YES' : 'NO'));
    }
}
