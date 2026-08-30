<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Filesystem last-known-good Help cache (no database).
 */
final class HelpCacheStore
{
    public function basePath(): string
    {
        $path = (string) config('help.cache.path', storage_path('app/help-cache'));

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    public function ensureReady(): void
    {
        $base = $this->basePath();
        if (! is_dir($base)) {
            mkdir($base, 0755, true);
        }
        $docs = $base.DIRECTORY_SEPARATOR.'docs';
        if (! is_dir($docs)) {
            mkdir($docs, 0755, true);
        }
    }

    public function cachedVersion(): ?string
    {
        $file = $this->basePath().DIRECTORY_SEPARATOR.'VERSION';
        if (! is_file($file)) {
            return null;
        }
        $version = trim((string) file_get_contents($file));
        $version = explode("\n", str_replace("\r\n", "\n", $version))[0] ?? '';

        return $version !== '' ? $version : null;
    }

    public function writeVersion(string $version): void
    {
        $this->ensureReady();
        file_put_contents($this->basePath().DIRECTORY_SEPARATOR.'VERSION', trim($version)."\n");
    }

    public function lastCheckedAt(): ?int
    {
        $meta = $this->readMeta();

        return isset($meta['checked_at']) ? (int) $meta['checked_at'] : null;
    }

    public function markChecked(): void
    {
        $meta = $this->readMeta();
        $meta['checked_at'] = time();
        $this->writeMeta($meta);
    }

    public function localSourceMtime(): ?int
    {
        $meta = $this->readMeta();

        return isset($meta['local_source_mtime']) ? (int) $meta['local_source_mtime'] : null;
    }

    public function writeLocalSourceMtime(int $mtime): void
    {
        $meta = $this->readMeta();
        $meta['local_source_mtime'] = $mtime;
        $meta['checked_at'] = time();
        $this->writeMeta($meta);
    }

    /**
     * @param  list<array<string, mixed>>  $topics
     */
    public function writeIndex(array $topics, string $version): void
    {
        $this->ensureReady();
        $payload = [
            'version' => $version,
            'built_at' => date('c'),
            'topics' => $topics,
        ];
        file_put_contents(
            $this->basePath().DIRECTORY_SEPARATOR.'index.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );
        $this->writeVersion($version);
        $this->markChecked();
    }

    /**
     * @return list<HelpTopic>
     */
    public function readTopics(): array
    {
        $file = $this->basePath().DIRECTORY_SEPARATOR.'index.json';
        if (! is_file($file)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json) || ! is_array($json['topics'] ?? null)) {
            return [];
        }

        $topics = [];
        foreach ($json['topics'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $topic = HelpTopic::fromArray($row);
            if ($topic->key !== '') {
                $topics[] = $topic;
            }
        }

        return $topics;
    }

    public function writeDocFile(string $relativePath, string $contents): void
    {
        $this->ensureReady();
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $full = $this->basePath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($full);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($full, $contents);
    }

    public function readDocFile(string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $full = $this->basePath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (! is_file($full)) {
            return null;
        }

        return (string) file_get_contents($full);
    }

    /**
     * Group display order overrides from Help repo `groups.json`.
     *
     * @return array<string, int> groupId => sort_order
     */
    public function readGroupSortOrders(): array
    {
        $file = $this->basePath().DIRECTORY_SEPARATOR.'groups.json';
        if (! is_file($file)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json)) {
            return [];
        }

        $raw = $json['sort_orders'] ?? $json;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $groupId => $order) {
            $gid = trim((string) $groupId);
            if ($gid === '' || ! is_numeric($order)) {
                continue;
            }
            $out[$gid] = (int) $order;
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $sortOrders
     */
    public function writeGroupSortOrders(array $sortOrders): void
    {
        $normalized = [];
        foreach ($sortOrders as $groupId => $order) {
            $gid = trim((string) $groupId);
            if ($gid === '') {
                continue;
            }
            $normalized[$gid] = (int) $order;
        }

        $this->ensureReady();
        $payload = [
            'version' => 1,
            'sort_orders' => $normalized,
            'updated_at' => date('c'),
        ];
        file_put_contents(
            $this->basePath().DIRECTORY_SEPARATOR.'groups.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    public function writeGroupOrdersRaw(string $json): void
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return;
        }
        $raw = $decoded['sort_orders'] ?? $decoded;
        if (! is_array($raw)) {
            return;
        }
        $orders = [];
        foreach ($raw as $groupId => $order) {
            if (! is_numeric($order)) {
                continue;
            }
            $orders[(string) $groupId] = (int) $order;
        }
        $this->writeGroupSortOrders($orders);
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(): array
    {
        $file = $this->basePath().DIRECTORY_SEPARATOR.'meta.json';
        if (! is_file($file)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($file), true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function writeMeta(array $meta): void
    {
        $this->ensureReady();
        file_put_contents(
            $this->basePath().DIRECTORY_SEPARATOR.'meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }
}
