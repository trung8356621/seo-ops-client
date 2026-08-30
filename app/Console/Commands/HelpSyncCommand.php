<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Help\HelpRemoteSyncService;
use Illuminate\Console\Command;

final class HelpSyncCommand extends Command
{
    protected $signature = 'help:sync
        {--force : Ignore VERSION / TTL and rebuild cache}
        {--local= : Rebuild from a local seo-ops-help directory}';

    protected $description = 'Sync public Help Markdown repo into filesystem cache';

    public function handle(HelpRemoteSyncService $sync): int
    {
        $local = $this->option('local');
        if (is_string($local) && $local !== '') {
            $result = $sync->rebuildFromLocalDirectory($local, 'local-'.date('Y.m.d'));
            $this->info('Rebuilt from local: '.$result['topic_count'].' topics @ '.$result['version']);

            return self::SUCCESS;
        }

        $result = $sync->sync(force: (bool) $this->option('force'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
