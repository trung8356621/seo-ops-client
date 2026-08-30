<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Sync public Help repo into filesystem cache using VERSION check.
 */
final class HelpRemoteSyncService
{
    public function __construct(
        private readonly HelpGitHubClient $github,
        private readonly HelpCacheStore $cache,
        private readonly HelpMarkdownDocument $documents,
        private readonly HelpMarkdownRenderer $renderer,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   updated: bool,
     *   version: string|null,
     *   from_cache: bool,
     *   error?: string,
     *   topic_count?: int
     * }
     */
    public function sync(bool $force = false): array
    {
        if (HelpLocalRepo::shouldUseLocal()) {
            return $this->syncFromLocalRepo($force);
        }

        $cachedVersion = $this->cache->cachedVersion();
        $ttl = (int) config('help.cache.check_ttl_seconds', 3600);
        $lastChecked = $this->cache->lastCheckedAt();

        if (! $force && $cachedVersion !== null && $lastChecked !== null && (time() - $lastChecked) < $ttl) {
            return [
                'ok' => true,
                'updated' => false,
                'version' => $cachedVersion,
                'from_cache' => true,
                'topic_count' => count($this->cache->readTopics()),
            ];
        }

        if (! $this->github->isConfiguredForRead()) {
            return [
                'ok' => $cachedVersion !== null,
                'updated' => false,
                'version' => $cachedVersion,
                'from_cache' => true,
                'error' => 'help_github_not_configured',
                'topic_count' => count($this->cache->readTopics()),
            ];
        }

        $remoteVersion = $this->github->fetchVersion();
        if ($remoteVersion === null) {
            $this->cache->markChecked();

            return [
                'ok' => $cachedVersion !== null,
                'updated' => false,
                'version' => $cachedVersion,
                'from_cache' => true,
                'error' => 'help_remote_unavailable',
                'topic_count' => count($this->cache->readTopics()),
            ];
        }

        if (! $force && $cachedVersion !== null && $cachedVersion === $remoteVersion) {
            $this->cache->markChecked();

            return [
                'ok' => true,
                'updated' => false,
                'version' => $cachedVersion,
                'from_cache' => true,
                'topic_count' => count($this->cache->readTopics()),
            ];
        }

        try {
            $topicCount = $this->rebuildCache($remoteVersion);
        } catch (\Throwable $e) {
            return [
                'ok' => $cachedVersion !== null,
                'updated' => false,
                'version' => $cachedVersion,
                'from_cache' => true,
                'error' => 'help_sync_failed:'.$e->getMessage(),
                'topic_count' => count($this->cache->readTopics()),
            ];
        }

        return [
            'ok' => true,
            'updated' => true,
            'version' => $remoteVersion,
            'from_cache' => false,
            'topic_count' => $topicCount,
        ];
    }

    /**
     * Local sibling seo-ops-help: rebuild when docs mtime changes (no GitHub / no long TTL).
     *
     * @return array{
     *   ok: bool,
     *   updated: bool,
     *   version: string|null,
     *   from_cache: bool,
     *   error?: string,
     *   topic_count?: int,
     *   source?: string
     * }
     */
    public function syncFromLocalRepo(bool $force = false): array
    {
        $root = HelpLocalRepo::path();
        if ($root === null) {
            return [
                'ok' => false,
                'updated' => false,
                'version' => $this->cache->cachedVersion(),
                'from_cache' => true,
                'error' => 'help_local_repo_missing',
                'topic_count' => count($this->cache->readTopics()),
            ];
        }

        $mtime = HelpLocalRepo::docsMtime();
        $cachedMtime = $this->cache->localSourceMtime();
        $cachedVersion = $this->cache->cachedVersion();

        if (! $force && $cachedVersion !== null && $cachedMtime !== null && $mtime > 0 && $mtime <= $cachedMtime) {
            $this->cache->markChecked();

            return [
                'ok' => true,
                'updated' => false,
                'version' => $cachedVersion,
                'from_cache' => true,
                'topic_count' => count($this->cache->readTopics()),
                'source' => 'local-repo',
            ];
        }

        $versionFile = $root.DIRECTORY_SEPARATOR.'VERSION';
        $version = is_file($versionFile)
            ? trim(explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($versionFile)))[0] ?? '')
            : '';
        if ($version === '') {
            $version = 'local-'.date('Y.m.d.His');
        } else {
            $version = 'local-'.$version;
        }

        try {
            $result = $this->rebuildFromLocalDirectory($root, $version);
            $this->cache->writeLocalSourceMtime($mtime);
        } catch (\Throwable $e) {
            return [
                'ok' => $cachedVersion !== null,
                'updated' => false,
                'version' => $cachedVersion,
                'from_cache' => true,
                'error' => 'help_local_sync_failed:'.$e->getMessage(),
                'topic_count' => count($this->cache->readTopics()),
                'source' => 'local-repo',
            ];
        }

        return [
            'ok' => true,
            'updated' => true,
            'version' => $result['version'],
            'from_cache' => false,
            'topic_count' => $result['topic_count'],
            'source' => 'local-repo',
        ];
    }

    /**
     * Rebuild cache from a local docs directory (tests / seed).
     *
     * @return array{ok: bool, version: string, topic_count: int}
     */
    public function rebuildFromLocalDirectory(string $root, string $version = 'local'): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $docs = $root.DIRECTORY_SEPARATOR.'docs';
        if (! is_dir($docs)) {
            throw new \InvalidArgumentException('Help docs directory missing: '.$docs);
        }

        $paths = $this->scanLocalMarkdown($docs);
        $topics = [];
        foreach ($paths as $absolute) {
            $relative = 'docs/'.ltrim(str_replace('\\', '/', substr($absolute, strlen($docs))), '/');
            $markdown = (string) file_get_contents($absolute);
            $topic = $this->documents->toTopic($markdown, $relative);
            $topic = $this->renderer->withHtml($topic);
            $this->cache->writeDocFile($relative, $markdown);
            $topics[] = $topic->toArray();
        }

        $this->assertValidCatalog($topics);
        $this->cache->writeIndex($topics, $version);
        $this->importLocalGroupOrders($root);

        return ['ok' => true, 'version' => $version, 'topic_count' => count($topics)];
    }

    private function rebuildCache(string $version): int
    {
        $files = $this->github->listDocsTree();
        $topics = [];

        foreach ($files as $file) {
            $path = $file['path'];
            $markdown = $this->github->fetchRawFile($path);
            if ($markdown === null) {
                continue;
            }
            $topic = $this->documents->toTopic($markdown, $path);
            $topic = $this->renderer->withHtml($topic);
            $this->cache->writeDocFile($path, $markdown);
            $topics[] = $topic->toArray();
        }

        $this->assertValidCatalog($topics);
        $this->cache->writeIndex($topics, $version);
        $this->importRemoteGroupOrders();

        return count($topics);
    }

    private function importLocalGroupOrders(string $root): void
    {
        $file = $root.DIRECTORY_SEPARATOR.'groups.json';
        if (! is_file($file)) {
            return;
        }
        $this->cache->writeGroupOrdersRaw((string) file_get_contents($file));
    }

    private function importRemoteGroupOrders(): void
    {
        $json = $this->github->fetchRawFile('groups.json');
        if ($json === null || trim($json) === '') {
            return;
        }
        $this->cache->writeGroupOrdersRaw($json);
    }

    /**
     * @param  list<array<string, mixed>>  $topics
     */
    private function assertValidCatalog(array $topics): void
    {
        $seen = [];
        foreach ($topics as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                throw new \InvalidArgumentException('Help catalog contains empty key');
            }
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Duplicate Help topic key: '.$key);
            }
            $seen[$key] = true;

            if (trim((string) ($row['body_markdown'] ?? '')) === '') {
                throw new \InvalidArgumentException('Help topic missing content: '.$key);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function scanLocalMarkdown(string $docsDir): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($docsDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $out[] = $file->getPathname();
        }
        sort($out);

        return $out;
    }
}
