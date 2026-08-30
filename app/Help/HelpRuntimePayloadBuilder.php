<?php

declare(strict_types=1);

namespace App\Help;

use Omnichannel\Addons\Seo\Support\SeoHelpRegistry;

/**
 * Builds Alpine Help modal payload: Git cache topics + legacy fallback groups.
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

        if ($topics === []) {
            return [
                'groups' => $legacy['groups'],
                'contexts' => $legacy['contexts'],
                'topic_by_key' => [],
                'context_keys' => HelpContextKeyRegistry::keys(),
                'help_version' => $this->cache->cachedVersion(),
                'source' => 'legacy',
            ];
        }

        $gitGroups = $this->groupsFromTopics($topics);

        return [
            'groups' => $this->mergeGroups($legacy['groups'] ?? [], $gitGroups),
            'contexts' => $this->mergedContexts(),
            'topic_by_key' => $this->topicByKeyMap($topics),
            'context_keys' => HelpContextKeyRegistry::keys(),
            'help_version' => $this->cache->cachedVersion(),
            'source' => $sourcePrefix.'+legacy',
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

        $ordered = [];
        foreach (HelpGroupRegistry::all() as $meta) {
            $id = $meta['id'];
            if (! isset($byId[$id])) {
                continue;
            }
            $ordered[] = $byId[$id];
            unset($byId[$id]);
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
        $groupIds = array_map(
            static fn (array $g): string => $g['id'],
            HelpGroupRegistry::all(),
        );

        foreach ($contexts as $id => &$context) {
            $existing = is_array($context['groupIds'] ?? null) ? $context['groupIds'] : [];
            $context['groupIds'] = array_values(array_unique(array_merge($existing, $groupIds)));
        }
        unset($context);

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
}
