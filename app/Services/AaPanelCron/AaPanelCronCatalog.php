<?php

declare(strict_types=1);

namespace App\Services\AaPanelCron;

/**
 * Builds the five SEO-OPS aaPanel cron commands and import-compatible JSON.
 */
final class AaPanelCronCatalog
{
    public function __construct(
        private readonly AaPanelCronPathValidator $validator,
    ) {}

    /**
     * @return list<array{
     *   id: string,
     *   name: string,
     *   purpose_key: string,
     *   queue_label: string,
     *   schedule: string,
     *   lock_file: string,
     *   log_file: string,
     *   command: string
     * }>
     */
    public function tasks(?string $projectDirectory = null, ?string $phpBinary = null): array
    {
        $project = $this->resolveProject($projectDirectory);
        $php = $this->resolvePhp($phpBinary);

        $out = [];
        foreach ($this->rawTasks() as $task) {
            $out[] = [
                'id' => (string) $task['id'],
                'name' => (string) $task['name'],
                'purpose_key' => (string) $task['purpose_key'],
                'queue_label' => (string) $task['queue_label'],
                'schedule' => 'every_minute',
                'lock_file' => (string) $task['lock_file'],
                'log_file' => (string) $task['log_file'],
                'command' => $this->buildCommand($task, $project, $php),
            ];
        }

        return $out;
    }

    public function taskCount(): int
    {
        return count($this->rawTasks());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportAaPanelJson(?string $projectDirectory = null, ?string $phpBinary = null): array
    {
        $defaults = config('aapanel_cron.aapanel_defaults', []);
        if (! is_array($defaults)) {
            $defaults = [];
        }

        $rows = [];
        foreach ($this->tasks($projectDirectory, $phpBinary) as $task) {
            $rows[] = array_merge($defaults, [
                'name' => $task['name'],
                'type' => 'minute-n',
                'where1' => '1',
                'status' => 1,
                'sName' => 'ALL',
                'sType' => 'toShell',
                'sBody' => $task['command'],
                'flock' => 1,
            ]);
        }

        return $rows;
    }

    public function exportJsonString(?string $projectDirectory = null, ?string $phpBinary = null): string
    {
        return (string) json_encode(
            $this->exportAaPanelJson($projectDirectory, $phpBinary),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function buildCommand(array $task, string $project, string $php): string
    {
        $lock = (string) $task['lock_file'];
        $log = (string) $task['log_file'];
        $kind = (string) ($task['kind'] ?? 'queue');

        if ($kind === 'schedule') {
            $artisan = 'artisan schedule:run';
        } else {
            $queues = (string) ($task['queues'] ?? 'default');
            $parts = [
                'artisan queue:work database',
                '--queue='.$queues,
                '--stop-when-empty',
                '--max-jobs='.(int) ($task['max_jobs'] ?? 10),
                '--timeout='.(int) ($task['timeout'] ?? 300),
                '--tries='.(int) ($task['tries'] ?? 3),
                '--sleep='.(int) ($task['sleep'] ?? 1),
            ];
            if (array_key_exists('max_time', $task) && $task['max_time'] !== null) {
                $parts[] = '--max-time='.(int) $task['max_time'];
            }
            $artisan = implode(' ', $parts);
        }

        return "cd {$project} || exit 1\n"
            ."/usr/bin/flock -n {$lock} {$php} {$artisan} >> {$log} 2>&1";
    }

    private function resolveProject(?string $override): string
    {
        if (is_string($override) && $override !== '') {
            return $this->validator->assertSafeProjectDirectory($override);
        }

        return $this->validator->assertSafeProjectDirectory(
            (string) config('aapanel_cron.defaults.project_directory')
        );
    }

    private function resolvePhp(?string $override): string
    {
        if (is_string($override) && $override !== '') {
            return $this->validator->assertSafePhpBinary($override);
        }

        return $this->validator->assertSafePhpBinary(
            (string) config('aapanel_cron.defaults.php_binary')
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawTasks(): array
    {
        $tasks = config('aapanel_cron.tasks', []);
        if (! is_array($tasks)) {
            return [];
        }

        /** @var list<array<string, mixed>> $list */
        $list = array_values(array_filter($tasks, static fn ($row): bool => is_array($row)));

        return $list;
    }
}
