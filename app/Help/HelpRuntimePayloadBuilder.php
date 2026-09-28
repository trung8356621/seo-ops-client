<?php

declare(strict_types=1);

namespace App\Help;

use Omnichannel\Addons\Seo\Support\SeoHelpRegistry; // legacy SEO groups/contexts fallback — do not add new client Help dependencies here

/**
 * Builds the global Help drawer payload: Git cache topics + legacy SEO fallback groups.
 * Canonical registry groups are included even when they have zero topics.
 */
final class HelpRuntimePayloadBuilder
{
    public function __construct(
        private readonly HelpCacheStore $cache,
        private readonly HelpRemoteSyncService $sync,
    ) {}

    /**
     * @return array{
     *   groups: list<array<string, mixed>>,
     *   contexts: array<string, array<string, mixed>>,
     *   topic_by_key: array<string, array{groupId: string, topicId: string}>,
     *   context_keys: list<string>,
     *   help_version: string|null,
     *   source: string
     * }
     */
    public function clientPayload(bool $attemptSync = true): array
    {
        if ($attemptSync) {
            try {
                $this->sync->sync(force: false);
            } catch (\Throwable) {
                // Never break SEO panel when Help remote fails.
            }
        }

        $topics = $this->cache->readTopics();

        $legacy = SeoHelpRegistry::clientPayload();
        $sourcePrefix = HelpLocalRepo::shouldUseLocal() ? 'local-repo' : 'git-cache';
        $legacyTopicByKey = $this->legacyTopicByKeyMap($legacy['groups'] ?? []);

        if ($topics === []) {
            return $this->finalizePayload(
                groups: is_array($legacy['groups'] ?? null) ? $legacy['groups'] : [],
                topicByKey: $legacyTopicByKey,
                source: 'legacy',
            );
        }

        $gitGroups = $this->groupsFromTopics($topics);

        return $this->finalizePayload(
            groups: $this->mergeGroups($legacy['groups'] ?? [], $gitGroups),
            topicByKey: array_merge($legacyTopicByKey, $this->topicByKeyMap($topics)),
            source: $sourcePrefix.'+legacy',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, array{groupId: string, topicId: string}>  $topicByKey
     * @return array{
     *   groups: list<array<string, mixed>>,
     *   contexts: array<string, array<string, mixed>>,
     *   topic_by_key: array<string, array{groupId: string, topicId: string}>,
     *   context_keys: list<string>,
     *   context_resolution: list<array<string, mixed>>,
     *   help_version: string|null,
     *   source: string
     * }
     */
    private function finalizePayload(array $groups, array $topicByKey, string $source): array
    {
        return [
            'groups' => $this->withCanonicalGroups($groups),
            'contexts' => $this->mergedContexts(),
            'topic_by_key' => $topicByKey,
            'context_keys' => HelpContextKeyRegistry::keys(),
            'context_resolution' => HelpContextResolver::steps(),
            'help_version' => $this->cache->cachedVersion(),
            'source' => $source,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $legacy
     * @param  list<array<string, mixed>>  $git
     * @return list<array<string, mixed>>
     */
    private function mergeGroups(array $legacy, array $git): array
    {
        $byId = [];
        foreach ($legacy as $group) {
            if (! is_array($group) || ! isset($group['id'])) {
                continue;
            }
            $byId[(string) $group['id']] = $group;
        }
        foreach ($git as $group) {
            $id = (string) ($group['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (! isset($byId[$id])) {
                $byId[$id] = $group;
                continue;
            }
            $existingTopics = is_array($byId[$id]['topics'] ?? null) ? $byId[$id]['topics'] : [];
            $incoming = is_array($group['topics'] ?? null) ? $group['topics'] : [];
            $seen = [];
            foreach ($existingTopics as $t) {
                if (is_array($t) && isset($t['id'])) {
                    $seen[(string) $t['id']] = true;
                }
            }
            foreach ($incoming as $t) {
                if (! is_array($t) || ! isset($t['id'])) {
                    continue;
                }
                $tid = (string) $t['id'];
                if (isset($seen[$tid])) {
                    // Git topic wins over legacy same id
                    $existingTopics = array_values(array_filter(
                        $existingTopics,
                        static fn ($row): bool => ! (is_array($row) && (string) ($row['id'] ?? '') === $tid),
                    ));
                }
                $existingTopics[] = $t;
                $seen[$tid] = true;
            }
            $byId[$id]['topics'] = $existingTopics;
            if (isset($group['title'])) {
                $byId[$id]['title'] = $group['title'];
            }
            if (isset($group['modalTitle'])) {
                $byId[$id]['modalTitle'] = $group['modalTitle'];
            }
        }

        return $this->withCanonicalGroups(array_values($byId));
    }

    /**
     * Canonical registry groups stay in the payload even when they have zero topics.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function withCanonicalGroups(array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            if (! is_array($group) || ! isset($group['id'])) {
                continue;
            }
            $id = (string) $group['id'];
            if ($id === '') {
                continue;
            }
            if (! isset($group['topics']) || ! is_array($group['topics'])) {
                $group['topics'] = [];
            }
            $byId[$id] = $group;
        }

        $ordered = [];
        foreach (HelpGroupRegistry::all() as $meta) {
            $id = $meta['id'];
            if (isset($byId[$id])) {
                $row = $byId[$id];
                if (! isset($row['title']) || $row['title'] === '') {
                    $row['title'] = $meta['title'];
                }
                if (! isset($row['modalTitle']) || $row['modalTitle'] === '') {
                    $row['modalTitle'] = $meta['modalTitle'];
                }
                $ordered[] = $row;
                unset($byId[$id]);

                continue;
            }

            $ordered[] = [
                'id' => $id,
                'title' => $meta['title'],
                'modalTitle' => $meta['modalTitle'],
                'topics' => [],
            ];
        }

        foreach ($byId as $group) {
            $ordered[] = $group;
        }

        return $ordered;
    }

    /**
     * @param  list<HelpTopic>  $topics
     * @return list<array<string, mixed>>
     */
    private function groupsFromTopics(array $topics): array
    {
        $registry = HelpGroupRegistry::all();
        $bucket = [];

        foreach ($topics as $topic) {
            $groupId = $topic->group;
            if (! isset($bucket[$groupId])) {
                $meta = HelpGroupRegistry::find($groupId);
                $bucket[$groupId] = [
                    'id' => $groupId,
                    'title' => (string) ($meta['title'] ?? $groupId),
                    'modalTitle' => (string) ($meta['modalTitle'] ?? ($meta['title'] ?? $groupId)),
                    'topics' => [],
                ];
            }
            $bucket[$groupId]['topics'][] = $topic->toClientTopic();
        }

        foreach ($bucket as &$group) {
            usort(
                $group['topics'],
                static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)),
            );
        }
        unset($group);

        $ordered = [];
        foreach ($registry as $meta) {
            $id = $meta['id'];
            if (isset($bucket[$id])) {
                $ordered[] = $bucket[$id];
                unset($bucket[$id]);
            }
        }
        foreach ($bucket as $group) {
            $ordered[] = $group;
        }

        return $ordered;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mergedContexts(): array
    {
        $contexts = SeoHelpRegistry::contexts();
        foreach (HelpPanelContextRegistry::contexts() as $id => $panelContext) {
            $contexts[$id] = $panelContext;
        }
        $groupIds = array_map(
            static fn (array $g): string => $g['id'],
            HelpGroupRegistry::all(),
        );

        if (! isset($contexts['system'])) {
            $contexts['system'] = [
                'id' => 'system',
                'modalTitle' => 'Hướng dẫn hệ thống',
                'defaultGroupId' => $groupIds[0] ?? 'getting-started',
                'routeNames' => [],
                'pathPatterns' => [],
                'groupIds' => $groupIds,
            ];
        }

        return $contexts;
    }

    /**
     * @param  list<HelpTopic>  $topics
     * @return array<string, array{groupId: string, topicId: string}>
     */
    private function topicByKeyMap(array $topics): array
    {
        $map = [];
        foreach ($topics as $topic) {
            $map[$topic->key] = [
                'groupId' => $topic->group,
                'topicId' => $topic->key,
            ];
        }

        return $map;
    }

    /**
     * Map legacy SeoHelpRegistry topics that declare a contextual `key` (or dotted id).
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, array{groupId: string, topicId: string}>
     */
    private function legacyTopicByKeyMap(array $groups): array
    {
        $map = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId === '') {
                continue;
            }
            $topics = is_array($group['topics'] ?? null) ? $group['topics'] : [];
            foreach ($topics as $topic) {
                if (! is_array($topic)) {
                    continue;
                }
                $topicId = trim((string) ($topic['id'] ?? ''));
                $key = trim((string) ($topic['key'] ?? $topicId));
                if ($key === '' || ! str_contains($key, '.')) {
                    continue;
                }
                $map[$key] = [
                    'groupId' => $groupId,
                    'topicId' => $topicId !== '' ? $topicId : $key,
                ];
            }
        }

        return $map;
    }
}
