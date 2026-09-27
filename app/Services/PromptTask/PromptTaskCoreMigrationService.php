<?php

declare(strict_types=1);

namespace App\Services\PromptTask;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Copy / verify Prompt + Task data from SEO DB (omi_seo_ai) to Core Client DB (mysql / omi_client).
 */
final class PromptTaskCoreMigrationService
{
    /** @var list<string> Exact copy order (parents first, dependents second) */
    public const TABLES = [
        'seo_tasks',
        'task_test_results',
        'prompts',
        'prompt_versions',
        'prompt_results',
        'prompt_result_routing_attempts',
    ];

    public static function sourceConnection(): string
    {
        return 'omi_seo_ai';
    }

    public static function targetConnection(): string
    {
        return (string) config('database.core_connection', config('database.default', 'mysql'));
    }

    /**
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        return $this->copy(execute: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function copy(bool $execute = true): array
    {
        $report = $this->newReport($execute ? 'copy' : 'dry-run');
        $source = self::sourceConnection();
        $target = self::targetConnection();
        $chunk = 500;

        if ($source === $target) {
            $report['errors'][] = 'source_connection và target_connection trùng nhau.';
            $report['cutover_ready'] = false;

            return $report;
        }

        foreach (self::TABLES as $table) {
            $tableReport = [
                'source_count' => 0,
                'target_count_before' => 0,
                'copied' => 0,
                'already_present' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'skipped' => 0,
                'status' => 'pending',
            ];

            try {
                if (! Schema::connection($source)->hasTable($table)) {
                    $tableReport['status'] = 'SKIPPED';
                    $tableReport['skipped'] = 1;
                    $report['tables'][$table] = $tableReport;
                    continue;
                }

                if (! Schema::connection($target)->hasTable($table)) {
                    $tableReport['status'] = 'FAILED';
                    $tableReport['failed'] = 1;
                    $report['errors'][] = "Thiếu bảng đích {$table} trên [{$target}]. Chạy migrate core trước.";
                    $report['tables'][$table] = $tableReport;
                    continue;
                }

                $sourceQuery = DB::connection($source)->table($table);
                $tableReport['source_count'] = (int) (clone $sourceQuery)->count();
                $tableReport['target_count_before'] = (int) DB::connection($target)->table($table)->count();

                if (! $execute) {
                    $tableReport['status'] = 'DRY_RUN';
                    $report['tables'][$table] = $tableReport;
                    continue;
                }

                $pk = 'id';
                $columns = $this->sharedColumns($source, $target, $table);

                if ($columns === []) {
                    $tableReport['status'] = 'FAILED';
                    $tableReport['failed'] = 1;
                    $report['errors'][] = "Không xác định được columns cho {$table}.";
                    $report['tables'][$table] = $tableReport;
                    continue;
                }

                $lastId = 0;
                while (true) {
                    $rows = DB::connection($source)
                        ->table($table)
                        ->where($pk, '>', $lastId)
                        ->orderBy($pk)
                        ->limit($chunk)
                        ->get();

                    if ($rows->isEmpty()) {
                        break;
                    }

                    foreach ($rows as $row) {
                        $lastId = (int) $row->{$pk};
                        $payload = [];
                        foreach ($columns as $col) {
                            $payload[$col] = $row->{$col} ?? null;
                        }

                        $existing = DB::connection($target)->table($table)->where($pk, $lastId)->first();
                        if ($existing === null) {
                            DB::connection($target)->table($table)->insert($payload);
                            $tableReport['copied']++;
                            continue;
                        }

                        if ($this->rowsEqual($payload, (array) $existing, $columns)) {
                            $tableReport['already_present']++;
                            continue;
                        }

                        $tableReport['conflicts']++;
                        $report['conflicts'][] = [
                            'table' => $table,
                            'id' => $lastId,
                            'status' => 'CONFLICT',
                        ];
                    }
                }

                $tableReport['target_count'] = (int) DB::connection($target)->table($table)->count();
                $tableReport['status'] = $tableReport['conflicts'] > 0 || $tableReport['failed'] > 0
                    ? 'CONFLICT'
                    : 'COPIED';
            } catch (Throwable $e) {
                $tableReport['status'] = 'FAILED';
                $tableReport['failed']++;
                $report['errors'][] = "{$table}: " . $e->getMessage();
            }

            $report['tables'][$table] = $tableReport;
        }

        $report['finished_at'] = now()->toIso8601String();
        $report['cutover_ready'] = $report['errors'] === [] && $report['conflicts'] === [];

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        $report = $this->newReport('verify');
        $source = self::sourceConnection();
        $target = self::targetConnection();
        $allMatch = true;

        foreach (self::TABLES as $table) {
            $entry = [
                'source_count' => 0,
                'target_count' => 0,
                'min_pk_source' => null,
                'max_pk_source' => null,
                'min_pk_target' => null,
                'max_pk_target' => null,
                'count_match' => false,
                'status' => 'pending',
            ];

            try {
                $sourceExists = Schema::connection($source)->hasTable($table);
                $targetExists = Schema::connection($target)->hasTable($table);

                if (! $sourceExists && ! $targetExists) {
                    $entry['status'] = 'SKIPPED';
                    $entry['count_match'] = true;
                    $report['tables'][$table] = $entry;
                    continue;
                }

                if (! $targetExists) {
                    $entry['status'] = 'TARGET_MISSING';
                    $allMatch = false;
                    $report['tables'][$table] = $entry;
                    continue;
                }

                $entry['target_count'] = (int) DB::connection($target)->table($table)->count();
                $entry['min_pk_target'] = DB::connection($target)->table($table)->min('id');
                $entry['max_pk_target'] = DB::connection($target)->table($table)->max('id');

                if (! $sourceExists) {
                    $entry['status'] = 'SOURCE_ABSENT';
                    $entry['count_match'] = true;
                    $report['tables'][$table] = $entry;
                    continue;
                }

                $entry['source_count'] = (int) DB::connection($source)->table($table)->count();
                $entry['min_pk_source'] = DB::connection($source)->table($table)->min('id');
                $entry['max_pk_source'] = DB::connection($source)->table($table)->max('id');

                $entry['count_match'] = ($entry['source_count'] === $entry['target_count'])
                    && ($entry['min_pk_source'] == $entry['min_pk_target'])
                    && ($entry['max_pk_source'] == $entry['max_pk_target']);

                if ($entry['count_match']) {
                    $entry['status'] = 'MATCH';
                } else {
                    $entry['status'] = 'MISMATCH';
                    $allMatch = false;
                }
            } catch (Throwable $e) {
                $entry['status'] = 'ERROR';
                $report['errors'][] = "{$table}: " . $e->getMessage();
                $allMatch = false;
            }

            $report['tables'][$table] = $entry;
        }

        $report['cutover_ready'] = $allMatch && $report['errors'] === [];
        $report['finished_at'] = now()->toIso8601String();

        return $report;
    }

    /**
     * @return list<string>
     */
    private function sharedColumns(string $source, string $target, string $table): array
    {
        $srcCols = Schema::connection($source)->getColumnListing($table);
        $tgtCols = Schema::connection($target)->getColumnListing($table);

        return array_values(array_intersect($srcCols, $tgtCols));
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @param array<string, mixed> $targetRow
     * @param list<string> $columns
     */
    private function rowsEqual(array $sourceRow, array $targetRow, array $columns): bool
    {
        foreach ($columns as $col) {
            $srcVal = $sourceRow[$col] ?? null;
            $tgtVal = $targetRow[$col] ?? null;

            if ($srcVal === null && $tgtVal === null) {
                continue;
            }

            if ($this->isJsonCol($srcVal) || $this->isJsonCol($tgtVal)) {
                if ($this->canonicalizeJson($srcVal) === $this->canonicalizeJson($tgtVal)) {
                    continue;
                }
            }

            if ((string) $srcVal !== (string) $tgtVal) {
                return false;
            }
        }

        return true;
    }

    private function isJsonCol(mixed $val): bool
    {
        if (! is_string($val)) {
            return false;
        }
        $val = trim($val);
        return (str_starts_with($val, '{') && str_ends_with($val, '}'))
            || (str_starts_with($val, '[') && str_ends_with($val, ']'));
    }

    private function canonicalizeJson(mixed $val): string
    {
        if ($val === null) {
            return '';
        }
        if (is_array($val)) {
            return (string) json_encode($val);
        }
        if (! is_string($val)) {
            return (string) $val;
        }
        $decoded = json_decode($val, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return (string) json_encode($decoded);
        }
        return trim($val);
    }

    /**
     * @return array<string, mixed>
     */
    private function newReport(string $phase): array
    {
        return [
            'phase' => $phase,
            'source_connection' => self::sourceConnection(),
            'target_connection' => self::targetConnection(),
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'tables' => [],
            'conflicts' => [],
            'errors' => [],
            'cutover_ready' => false,
        ];
    }
}
