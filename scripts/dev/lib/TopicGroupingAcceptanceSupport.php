<?php

declare(strict_types=1);

/**
 * Pure comparison helpers for topic-grouping-final-acceptance.php (TASK 6.1).
 * No Laravel bootstrap required for unit tests of these static methods.
 */
final class TopicGroupingAcceptanceSupport
{
    /**
     * @param  array<int, int>  $before  keyword_id => article_id
     * @param  array<int, int>  $after
     */
    public static function focusMapsIdentical(array $before, array $after): bool
    {
        ksort($before);
        ksort($after);

        return $before === $after;
    }

    /**
     * @param  list<int>  $beforeIds
     * @param  list<int>  $afterIds
     */
    public static function articleIdSetsIdentical(array $beforeIds, array $afterIds): bool
    {
        $beforeIds = array_values(array_unique(array_map('intval', $beforeIds)));
        $afterIds = array_values(array_unique(array_map('intval', $afterIds)));
        sort($beforeIds);
        sort($afterIds);

        return $beforeIds === $afterIds;
    }

    /**
     * Locked memberships: each before (keyword_id) with is_locked must still have a locked membership after.
     *
     * @param  list<array{keyword_id: int, topic_id: int, is_locked: bool}>  $before
     * @param  list<array{keyword_id: int, topic_id: int, is_locked: bool}>  $after
     * @return list<int> keyword_ids that lost lock
     */
    public static function missingLockedMembershipKeywordIds(array $before, array $after): array
    {
        $afterLocked = [];
        foreach ($after as $row) {
            if (! empty($row['is_locked'])) {
                $afterLocked[(int) $row['keyword_id']] = true;
            }
        }
        $missing = [];
        foreach ($before as $row) {
            if (empty($row['is_locked'])) {
                continue;
            }
            $kw = (int) $row['keyword_id'];
            if ($kw > 0 && ! isset($afterLocked[$kw])) {
                $missing[] = $kw;
            }
        }

        return $missing;
    }

    /**
     * Manual Topics: every before manual topic_id must still exist as manual after.
     *
     * @param  list<array{id: int, source: string}>  $beforeTopics
     * @param  list<array{id: int, source: string}>  $afterTopics
     * @return list<int>
     */
    public static function missingManualTopicIds(array $beforeTopics, array $afterTopics): array
    {
        $afterById = [];
        foreach ($afterTopics as $t) {
            $afterById[(int) $t['id']] = (string) ($t['source'] ?? '');
        }
        $missing = [];
        foreach ($beforeTopics as $t) {
            if (($t['source'] ?? '') !== 'manual') {
                continue;
            }
            $id = (int) $t['id'];
            if ($id <= 0) {
                continue;
            }
            if (($afterById[$id] ?? '') !== 'manual') {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * Locked Topics: every before locked topic_id must still be locked after.
     *
     * @param  list<array{id: int, is_locked: bool}>  $beforeTopics
     * @param  list<array{id: int, is_locked: bool}>  $afterTopics
     * @return list<int>
     */
    public static function missingLockedTopicIds(array $beforeTopics, array $afterTopics): array
    {
        $afterLocked = [];
        foreach ($afterTopics as $t) {
            if (! empty($t['is_locked'])) {
                $afterLocked[(int) $t['id']] = true;
            }
        }
        $missing = [];
        foreach ($beforeTopics as $t) {
            if (empty($t['is_locked'])) {
                continue;
            }
            $id = (int) $t['id'];
            if ($id > 0 && ! isset($afterLocked[$id])) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * Manual tag assignments must remain on same topic or planned successor (metadata_migrations).
     *
     * @param  list<array{topic_id: int, tag_id: int, source?: string}>  $beforeTags
     * @param  list<array{topic_id: int, tag_id: int, source?: string}>  $afterTags
     * @param  list<array{type?: string, from_topic_id?: int, to_topic_id?: int, tag_id?: int, source?: string}>  $metadataMigrations
     * @return list<string> failure messages
     */
    public static function manualTagFailures(array $beforeTags, array $afterTags, array $metadataMigrations): array
    {
        $afterSet = [];
        foreach ($afterTags as $row) {
            if (($row['source'] ?? 'manual') !== 'manual' && ($row['source'] ?? '') !== '') {
                // still allow checking manual-only before rows against any after presence
            }
            $key = ((int) $row['topic_id']).':'.((int) $row['tag_id']);
            $afterSet[$key] = true;
        }

        /** @var array<string, int> $migrateTo tagKey(from:tag) => to_topic_id */
        $migrateTo = [];
        foreach ($metadataMigrations as $m) {
            if (($m['type'] ?? '') !== 'tag_reassign') {
                continue;
            }
            if (($m['source'] ?? 'manual') !== 'manual') {
                continue;
            }
            $from = (int) ($m['from_topic_id'] ?? 0);
            $to = (int) ($m['to_topic_id'] ?? 0);
            $tagId = (int) ($m['tag_id'] ?? 0);
            if ($from > 0 && $to > 0 && $tagId > 0) {
                $migrateTo[$from.':'.$tagId] = $to;
            }
        }

        $failures = [];
        foreach ($beforeTags as $row) {
            if (($row['source'] ?? 'manual') !== 'manual') {
                continue;
            }
            $topicId = (int) $row['topic_id'];
            $tagId = (int) $row['tag_id'];
            if ($topicId <= 0 || $tagId <= 0) {
                continue;
            }
            $sameKey = $topicId.':'.$tagId;
            if (isset($afterSet[$sameKey])) {
                continue;
            }
            $to = $migrateTo[$sameKey] ?? 0;
            if ($to > 0 && isset($afterSet[$to.':'.$tagId])) {
                continue;
            }
            $failures[] = "manual_tag_lost topic_id={$topicId} tag_id={$tagId}";
        }

        return $failures;
    }

    /**
     * MCP-excluded topics before must remain excluded on same id or planned policy successor.
     *
     * @param  list<array{id: int, mcp_excluded: bool}>  $beforeTopics
     * @param  list<array{id: int, mcp_excluded: bool}>  $afterTopics
     * @param  list<array{type?: string, topic_id?: int, from_topic_id?: int, group_key?: string}>  $policyMigrations
     * @return list<string>
     */
    public static function mcpExclusionFailures(array $beforeTopics, array $afterTopics, array $policyMigrations): array
    {
        $afterExcluded = [];
        foreach ($afterTopics as $t) {
            if (! empty($t['mcp_excluded'])) {
                $afterExcluded[(int) $t['id']] = true;
            }
        }

        $successorByFrom = [];
        foreach ($policyMigrations as $m) {
            $type = (string) ($m['type'] ?? '');
            $from = (int) ($m['from_topic_id'] ?? 0);
            if ($from <= 0) {
                continue;
            }
            if ($type === 'mcp_exclude') {
                $to = (int) ($m['topic_id'] ?? 0);
                if ($to > 0) {
                    $successorByFrom[$from][] = $to;
                }
            }
            // mcp_exclude_group applied at apply-time by group_key — verified via afterExcluded presence
            // on newly created topics is covered by plan metrics; here we require from still excluded
            // OR an mcp_exclude successor exists and is excluded.
        }

        $failures = [];
        foreach ($beforeTopics as $t) {
            if (empty($t['mcp_excluded'])) {
                continue;
            }
            $id = (int) $t['id'];
            if ($id <= 0) {
                continue;
            }
            if (isset($afterExcluded[$id])) {
                continue;
            }
            $ok = false;
            foreach ($successorByFrom[$id] ?? [] as $to) {
                if (isset($afterExcluded[$to])) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                // Dissolved + group_key propagation: from may be gone; require at least one migration entry.
                $hasGroupMig = false;
                foreach ($policyMigrations as $m) {
                    if (($m['type'] ?? '') === 'mcp_exclude_group' && (int) ($m['from_topic_id'] ?? 0) === $id) {
                        $hasGroupMig = true;
                        break;
                    }
                }
                if ($hasGroupMig) {
                    // Successor topic_id is runtime; acceptance confirms no silent loss via migration presence
                    // plus after mcp_excluded count >= planned propagations is checked separately.
                    continue;
                }
                $failures[] = "mcp_exclusion_lost topic_id={$id}";
            }
        }

        return $failures;
    }

    /**
     * @param  array{move?: int, create?: int, dissolve?: int}  $first
     * @param  array{move?: int, create?: int, dissolve?: int}  $second
     * @return array{classification: string, ratios: array{move: float|null, create: float|null, dissolve: float|null}}
     */
    public static function classifyConvergence(array $first, array $second): array
    {
        $fMove = (int) ($first['move'] ?? 0);
        $fCreate = (int) ($first['create'] ?? 0);
        $fDissolve = (int) ($first['dissolve'] ?? 0);
        $sMove = (int) ($second['move'] ?? 0);
        $sCreate = (int) ($second['create'] ?? 0);
        $sDissolve = (int) ($second['dissolve'] ?? 0);

        $ratio = static function (int $second, int $first): ?float {
            if ($first <= 0) {
                return $second <= 0 ? 0.0 : null;
            }

            return round($second / $first, 4);
        };

        $ratios = [
            'move' => $ratio($sMove, $fMove),
            'create' => $ratio($sCreate, $fCreate),
            'dissolve' => $ratio($sDissolve, $fDissolve),
        ];

        $allSmall = true;
        $allLarge = true;
        foreach (['move', 'create', 'dissolve'] as $k) {
            $r = $ratios[$k];
            if ($r === null) {
                // Second has churn where first had zero → not stable.
                $allSmall = false;
                $allLarge = false;
                continue;
            }
            if ($r > 0.25) {
                $allSmall = false;
            }
            if ($r < 0.5) {
                $allLarge = false;
            }
        }

        // STABLE: second pass ≤25% of first on move/create/dissolve (justified cutover settling band).
        // NOT_CONVERGING: second pass ≥50% of first on all three axes (rewrite comparable to cutover).
        // Else NEEDS_REVIEW.
        $classification = 'NEEDS_REVIEW';
        if ($allSmall) {
            $classification = 'STABLE';
        } elseif ($allLarge) {
            $classification = 'NOT_CONVERGING';
        }

        return [
            'classification' => $classification,
            'ratios' => $ratios,
        ];
    }

    /**
     * @param  array<string, int|float|null>  $planCounts
     * @param  array<string, mixed>  $applyMetrics
     * @return array<string, string> metric => PASS|FAIL|NOT_DIRECTLY_VERIFIABLE
     */
    public static function previewVsActual(array $planCounts, array $applyMetrics): array
    {
        $out = [];
        foreach (['topics_created', 'topics_reused', 'topics_dissolved'] as $key) {
            if (! array_key_exists($key, $planCounts) || ! array_key_exists($key, $applyMetrics)) {
                $out[$key] = 'NOT_DIRECTLY_VERIFIABLE';
                continue;
            }
            $out[$key] = (int) $planCounts[$key] === (int) $applyMetrics[$key] ? 'PASS' : 'FAIL';
        }

        if (isset($planCounts['effective_topics_after'], $applyMetrics['topics_after'])) {
            $out['effective_topics_after'] = (int) $planCounts['effective_topics_after'] === (int) $applyMetrics['topics_after']
                ? 'PASS'
                : 'FAIL';
        } else {
            $out['effective_topics_after'] = 'NOT_DIRECTLY_VERIFIABLE';
        }

        // memberships_written is persistence write count — not equal to keep+assign+move.
        $out['keywords_keep_assign_move_unassign'] = 'NOT_DIRECTLY_VERIFIABLE';
        $out['memberships_written'] = array_key_exists('memberships_written', $applyMetrics)
            ? 'REPORTED'
            : 'NOT_DIRECTLY_VERIFIABLE';

        return $out;
    }

    public static function envFlag(string $name): bool
    {
        $raw = (string) (getenv($name) ?: '');
        if ($raw === '' && function_exists('env')) {
            $raw = (string) env($name, '');
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes'], true);
    }
}
