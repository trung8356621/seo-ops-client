<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Canonical Help group registry — shared by Global Help modal and Help Admin.
 * Base metadata = config/help.php. Display sort_order may be overridden by
 * Help repo `groups.json` (filesystem cache) so Admin + Global Help stay in sync.
 */
final class HelpGroupRegistry
{
    /**
     * @return list<array{id: string, title: string, modalTitle: string, sort_order: int, context_prefix: string}>
     */
    public static function all(): array
    {
        $raw = config('help.groups', []);
        if (! is_array($raw)) {
            return [];
        }

        $orderOverrides = app(HelpCacheStore::class)->readGroupSortOrders();

        $groups = [];
        $index = 0;
        foreach ($raw as $id => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $gid = (string) ($meta['id'] ?? $id);
            if ($gid === '') {
                continue;
            }
            $defaultOrder = (int) ($meta['sort_order'] ?? ($index * 10));
            $groups[] = [
                'id' => $gid,
                'title' => (string) ($meta['title'] ?? $gid),
                'modalTitle' => (string) ($meta['modalTitle'] ?? ($meta['title'] ?? $gid)),
                'sort_order' => array_key_exists($gid, $orderOverrides)
                    ? (int) $orderOverrides[$gid]
                    : $defaultOrder,
                'context_prefix' => (string) ($meta['context_prefix'] ?? $gid),
            ];
            $index++;
        }

        usort(
            $groups,
            static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']
                ?: strcmp($a['id'], $b['id']),
        );

        return $groups;
    }

    /**
     * @return array{id: string, title: string, modalTitle: string, sort_order: int, context_prefix: string}|null
     */
    public static function find(string $groupId): ?array
    {
        foreach (self::all() as $group) {
            if ($group['id'] === $groupId) {
                return $group;
            }
        }

        return null;
    }

    public static function contextPrefix(string $groupId): string
    {
        return self::find($groupId)['context_prefix'] ?? $groupId;
    }

    /**
     * @return array<string, string> id => title
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $group) {
            $out[$group['id']] = $group['title'];
        }

        return $out;
    }

    /**
     * Next topic sort_order within a group (max existing + 10).
     */
    public static function nextTopicSortOrder(string $groupId): int
    {
        $max = 0;
        foreach (app(HelpCacheStore::class)->readTopics() as $topic) {
            if ($topic->group !== $groupId) {
                continue;
            }
            $max = max($max, $topic->sortOrder);
        }

        return $max + 10;
    }
}
