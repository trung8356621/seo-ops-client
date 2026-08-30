<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Compare client context registry vs Help topics (Covered / Missing / Unused).
 */
final class HelpCoverageService
{
    public function __construct(
        private readonly HelpCacheStore $cache,
    ) {}

    /**
     * @return array{
     *   covered: list<string>,
     *   missing: list<string>,
     *   unused: list<string>,
     *   rows: list<array<string, mixed>>
     * }
     */
    public function analyze(): array
    {
        $registryKeys = HelpContextKeyRegistry::keys();
        $topics = $this->cache->readTopics();
        $byKey = [];
        foreach ($topics as $topic) {
            $byKey[$topic->key] = $topic;
        }

        $covered = [];
        $missing = [];
        $unused = [];
        $rows = [];

        foreach ($registryKeys as $key) {
            $topic = $byKey[$key] ?? null;
            if ($topic === null) {
                $missing[] = $key;
                $rows[] = $this->row($key, 'missing', null);
                continue;
            }
            $covered[] = $key;
            $rows[] = $this->row($key, 'covered', $topic);
        }

        foreach ($topics as $topic) {
            if (! in_array($topic->key, $registryKeys, true)) {
                $unused[] = $topic->key;
                $rows[] = $this->row($topic->key, 'unused', $topic);
            }
        }

        return compact('covered', 'missing', 'unused', 'rows');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $key, string $coverage, ?HelpTopic $topic): array
    {
        return [
            'key' => $key,
            'coverage' => $coverage,
            'title' => $topic?->title,
            'group' => $topic?->group,
            'path' => $topic?->path,
            'updated_at' => $topic?->updatedAt,
        ];
    }
}
