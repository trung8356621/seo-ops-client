<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Admin publish: write Markdown to local sibling repo (dev) or GitHub Contents API (prod).
 * File exists on Git / local repo = topic exists (no draft/published status).
 */
final class HelpPublishService
{
    public function __construct(
        private readonly HelpGitHubClient $github,
        private readonly HelpMarkdownDocument $documents,
        private readonly HelpHtmlToMarkdownConverter $htmlToMarkdown,
        private readonly HelpRemoteSyncService $sync,
        private readonly HelpMarkdownRenderer $renderer,
    ) {}

    /**
     * @param  array{
     *   key: string,
     *   title: string,
     *   summary: string,
     *   group: string,
     *   sort_order?: int,
     *   keywords?: list<string>,
     *   html: string,
     *   path?: string|null
     * }  $input
     * @return array{ok: bool, path?: string, version?: string, error?: string, source?: string}
     */
    public function publish(array $input): array
    {
        $key = trim((string) ($input['key'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $summary = trim((string) ($input['summary'] ?? ''));
        $group = trim((string) ($input['group'] ?? ''));
        $html = (string) ($input['html'] ?? '');

        if ($key === '' || $title === '' || $summary === '' || $group === '') {
            return ['ok' => false, 'error' => 'help_publish_validation_failed'];
        }

        $bodyMarkdown = $this->htmlToMarkdown->convert($html);
        if (trim($bodyMarkdown) === '') {
            return ['ok' => false, 'error' => 'help_publish_empty_content'];
        }

        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            $slug = $this->pathSlug($key);
            $path = 'docs/'.$group.'/'.$slug.'.md';
        }

        $meta = [
            'key' => $key,
            'title' => $title,
            'summary' => $summary,
            'group' => $group,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
            'keywords' => array_values(array_map('strval', is_array($input['keywords'] ?? null) ? $input['keywords'] : [])),
            'updated_at' => date('Y-m-d H:i'),
        ];

        $markdown = $this->documents->serialize($meta, $bodyMarkdown);
        $this->documents->toTopic($markdown, $path);
        $this->renderer->toHtml($bodyMarkdown);

        if (HelpLocalRepo::shouldUseLocal()) {
            return $this->publishLocal($path, $markdown);
        }

        if (! $this->github->isConfiguredForWrite()) {
            return ['ok' => false, 'error' => 'help_github_write_not_configured'];
        }

        $existing = $this->github->getContentsFile($path);
        if (($existing['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string) ($existing['error'] ?? 'help_github_get_failed')];
        }

        $put = $this->github->putFile(
            $path,
            $markdown,
            'help: update '.$key,
            $existing['sha'] ?? null,
        );
        if (($put['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string) ($put['error'] ?? 'help_github_put_failed')];
        }

        $newVersion = date('Y.m.d').'.'.substr((string) time(), -4);
        $versionFile = $this->github->getContentsFile('VERSION');
        $versionPut = $this->github->putFile(
            'VERSION',
            $newVersion."\n",
            'help: bump VERSION after '.$key,
            $versionFile['sha'] ?? null,
        );
        if (($versionPut['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string) ($versionPut['error'] ?? 'help_version_bump_failed')];
        }

        $this->sync->sync(force: true);

        return [
            'ok' => true,
            'path' => $path,
            'version' => $newVersion,
            'source' => 'github',
        ];
    }

    public function previewHtmlFromEditorHtml(string $html): string
    {
        $markdown = $this->htmlToMarkdown->convert($html);

        return $this->renderer->toHtml($markdown);
    }

    /**
     * Persist group display order (not topic sort_order).
     *
     * @return array{ok: bool, published?: bool, error?: string}
     */
    public function updateGroupSortOrder(string $groupId, int $sortOrder): array
    {
        $groupId = trim($groupId);
        if ($groupId === '' || HelpGroupRegistry::find($groupId) === null) {
            return ['ok' => false, 'error' => 'help_unknown_group'];
        }

        $orders = [];
        foreach (HelpGroupRegistry::all() as $group) {
            $orders[$group['id']] = (int) $group['sort_order'];
        }
        $orders[$groupId] = $sortOrder;

        $cache = app(HelpCacheStore::class);
        $cache->writeGroupSortOrders($orders);

        $payload = json_encode(
            [
                'version' => 1,
                'sort_orders' => $orders,
                'updated_at' => date('c'),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )."\n";

        if (HelpLocalRepo::shouldUseLocal()) {
            $root = HelpLocalRepo::path();
            if ($root === null) {
                return ['ok' => true, 'published' => false, 'error' => 'help_local_repo_missing'];
            }
            file_put_contents($root.DIRECTORY_SEPARATOR.'groups.json', $payload);
            $this->sync->sync(force: true);

            return ['ok' => true, 'published' => true];
        }

        if (! $this->github->isConfiguredForWrite()) {
            return ['ok' => true, 'published' => false];
        }

        $existing = $this->github->getContentsFile('groups.json');
        if (($existing['ok'] ?? false) !== true) {
            return ['ok' => true, 'published' => false, 'error' => (string) ($existing['error'] ?? 'help_github_get_failed')];
        }

        $put = $this->github->putFile(
            'groups.json',
            $payload,
            'help: update group sort_order '.$groupId,
            $existing['sha'] ?? null,
        );
        if (($put['ok'] ?? false) !== true) {
            return ['ok' => true, 'published' => false, 'error' => (string) ($put['error'] ?? 'help_github_put_failed')];
        }

        $newVersion = date('Y.m.d').'.'.substr((string) time(), -4);
        $versionFile = $this->github->getContentsFile('VERSION');
        $versionPut = $this->github->putFile(
            'VERSION',
            $newVersion."\n",
            'help: bump VERSION after group order',
            $versionFile['sha'] ?? null,
        );
        if (($versionPut['ok'] ?? false) !== true) {
            return ['ok' => true, 'published' => false, 'error' => (string) ($versionPut['error'] ?? 'help_version_bump_failed')];
        }

        return ['ok' => true, 'published' => true];
    }

    /**
     * @return array{ok: bool, path?: string, version?: string, error?: string, source?: string}
     */
    private function publishLocal(string $path, string $markdown): array
    {
        $root = HelpLocalRepo::path();
        if ($root === null) {
            return ['ok' => false, 'error' => 'help_local_repo_missing'];
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $full = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $dir = dirname($full);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return ['ok' => false, 'error' => 'help_local_write_failed'];
        }

        if (file_put_contents($full, $markdown) === false) {
            return ['ok' => false, 'error' => 'help_local_write_failed'];
        }

        $version = 'local-'.date('Y.m.d.His');
        file_put_contents($root.DIRECTORY_SEPARATOR.'VERSION', $version."\n");

        $this->sync->sync(force: true);

        return [
            'ok' => true,
            'path' => $relative,
            'version' => $version,
            'source' => 'local-repo',
        ];
    }

    private function pathSlug(string $key): string
    {
        $parts = explode('.', $key);
        $leaf = (string) end($parts);
        $slug = strtolower(str_replace('_', '-', $leaf));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? $slug;

        return trim($slug, '-') ?: 'topic';
    }
}
