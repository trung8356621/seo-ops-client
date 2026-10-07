<?php

declare(strict_types=1);

namespace Tests\Unit\Dev;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/scripts/dev/lib/TopicGroupingAcceptanceSupport.php';

final class TopicGroupingAcceptanceSupportTest extends TestCase
{
    public function test_focus_maps_identical(): void
    {
        self::assertTrue(\TopicGroupingAcceptanceSupport::focusMapsIdentical(
            [10 => 100, 11 => 101],
            [11 => 101, 10 => 100],
        ));
        self::assertFalse(\TopicGroupingAcceptanceSupport::focusMapsIdentical(
            [10 => 100],
            [10 => 999],
        ));
    }

    public function test_article_id_sets_identical(): void
    {
        self::assertTrue(\TopicGroupingAcceptanceSupport::articleIdSetsIdentical([3, 1, 2], [1, 2, 3]));
        self::assertFalse(\TopicGroupingAcceptanceSupport::articleIdSetsIdentical([1, 2], [1]));
    }

    public function test_locked_membership_same_topic_pass(): void
    {
        $before = [
            ['keyword_id' => 1, 'topic_id' => 9, 'is_locked' => true],
            ['keyword_id' => 2, 'topic_id' => 9, 'is_locked' => false],
        ];
        $after = [
            ['keyword_id' => 1, 'topic_id' => 9, 'is_locked' => true],
            ['keyword_id' => 2, 'topic_id' => 12, 'is_locked' => false],
        ];
        self::assertSame([], \TopicGroupingAcceptanceSupport::lockedMembershipFailures($before, $after));
    }

    public function test_locked_membership_moved_topic_fail(): void
    {
        $before = [['keyword_id' => 1, 'topic_id' => 9, 'is_locked' => true]];
        $after = [['keyword_id' => 1, 'topic_id' => 12, 'is_locked' => true]];
        $fails = \TopicGroupingAcceptanceSupport::lockedMembershipFailures($before, $after);
        self::assertNotSame([], $fails);
        self::assertStringContainsString('locked_membership_moved', $fails[0]);
        self::assertStringContainsString('from_topic_id=9', $fails[0]);
        self::assertStringContainsString('to_topic_id=12', $fails[0]);
    }

    public function test_locked_membership_unlocked_fail(): void
    {
        $before = [['keyword_id' => 1, 'topic_id' => 9, 'is_locked' => true]];
        $after = [['keyword_id' => 1, 'topic_id' => 9, 'is_locked' => false]];
        $fails = \TopicGroupingAcceptanceSupport::lockedMembershipFailures($before, $after);
        self::assertNotSame([], $fails);
        self::assertStringContainsString('locked_membership_unlocked', $fails[0]);
    }

    public function test_locked_membership_missing_fail(): void
    {
        $before = [['keyword_id' => 1, 'topic_id' => 9, 'is_locked' => true]];
        $fails = \TopicGroupingAcceptanceSupport::lockedMembershipFailures($before, []);
        self::assertNotSame([], $fails);
        self::assertStringContainsString('locked_membership_missing', $fails[0]);
    }

    public function test_mcp_group_key_maps_to_excluded_topic_pass(): void
    {
        $before = [['id' => 5, 'mcp_excluded' => true]];
        $after = [['id' => 88, 'mcp_excluded' => true]];
        $policy = [[
            'type' => 'mcp_exclude_group',
            'group_key' => 'g-x',
            'from_topic_id' => 5,
        ]];
        $map = ['g-x' => 88];
        self::assertSame([], \TopicGroupingAcceptanceSupport::mcpExclusionFailures($before, $after, $policy, $map));
    }

    public function test_mcp_group_key_maps_to_non_excluded_topic_fail(): void
    {
        $before = [['id' => 5, 'mcp_excluded' => true]];
        $after = [
            ['id' => 88, 'mcp_excluded' => false],
            ['id' => 99, 'mcp_excluded' => true], // unrelated excluded must not satisfy
        ];
        $policy = [[
            'type' => 'mcp_exclude_group',
            'group_key' => 'g-x',
            'from_topic_id' => 5,
        ]];
        $fails = \TopicGroupingAcceptanceSupport::mcpExclusionFailures($before, $after, $policy, ['g-x' => 88]);
        self::assertTrue(count(array_filter($fails, static fn (string $m): bool => str_contains($m, 'mcp_exclude_group_not_excluded'))) >= 1);
    }

    public function test_mcp_group_key_missing_from_map_fail(): void
    {
        $before = [['id' => 5, 'mcp_excluded' => true]];
        $after = [['id' => 88, 'mcp_excluded' => true]];
        $policy = [[
            'type' => 'mcp_exclude_group',
            'group_key' => 'g-x',
            'from_topic_id' => 5,
        ]];
        $fails = \TopicGroupingAcceptanceSupport::mcpExclusionFailures($before, $after, $policy, []);
        self::assertTrue(count(array_filter($fails, static fn (string $m): bool => str_contains($m, 'mcp_exclude_group_unmapped'))) >= 1);
    }

    public function test_mcp_unrelated_excluded_does_not_satisfy_target(): void
    {
        $before = [['id' => 5, 'mcp_excluded' => true]];
        $after = [
            ['id' => 88, 'mcp_excluded' => false],
            ['id' => 99, 'mcp_excluded' => true],
        ];
        $policy = [[
            'type' => 'mcp_exclude_group',
            'group_key' => 'g-x',
            'from_topic_id' => 5,
        ]];
        $fails = \TopicGroupingAcceptanceSupport::mcpExclusionFailures($before, $after, $policy, ['g-x' => 88]);
        self::assertNotSame([], $fails);
        self::assertStringContainsString('topic_id=88', implode(' ', $fails));
    }

    public function test_manual_tag_migrations(): void
    {
        $before = [['topic_id' => 5, 'tag_id' => 7, 'source' => 'manual']];
        $afterMigrated = [['topic_id' => 9, 'tag_id' => 7, 'source' => 'manual']];
        $migrations = [[
            'type' => 'tag_reassign',
            'from_topic_id' => 5,
            'to_topic_id' => 9,
            'tag_id' => 7,
            'source' => 'manual',
        ]];
        self::assertSame([], \TopicGroupingAcceptanceSupport::manualTagFailures($before, $afterMigrated, $migrations));
        self::assertNotSame([], \TopicGroupingAcceptanceSupport::manualTagFailures($before, [], []));
    }

    public function test_convergence_classification_bands(): void
    {
        $stable = \TopicGroupingAcceptanceSupport::classifyConvergence(
            ['move' => 356, 'create' => 70, 'dissolve' => 30],
            ['move' => 20, 'create' => 5, 'dissolve' => 2],
        );
        self::assertSame('STABLE', $stable['classification']);

        $rewrite = \TopicGroupingAcceptanceSupport::classifyConvergence(
            ['move' => 356, 'create' => 70, 'dissolve' => 30],
            ['move' => 300, 'create' => 60, 'dissolve' => 25],
        );
        self::assertSame('NOT_CONVERGING', $rewrite['classification']);

        $mid = \TopicGroupingAcceptanceSupport::classifyConvergence(
            ['move' => 356, 'create' => 70, 'dissolve' => 30],
            ['move' => 120, 'create' => 20, 'dissolve' => 8],
        );
        self::assertSame('NEEDS_REVIEW', $mid['classification']);
    }

    public function test_preview_vs_actual_reports_unverifiable_keyword_axes(): void
    {
        $out = \TopicGroupingAcceptanceSupport::previewVsActual(
            [
                'topics_created' => 70,
                'topics_reused' => 27,
                'topics_dissolved' => 30,
                'effective_topics_after' => 97,
            ],
            [
                'topics_created' => 70,
                'topics_reused' => 27,
                'topics_dissolved' => 30,
                'topics_after' => 97,
                'memberships_written' => 500,
            ],
        );
        self::assertSame('PASS', $out['topics_created']);
        self::assertSame('PASS', $out['effective_topics_after']);
        self::assertSame('NOT_DIRECTLY_VERIFIABLE', $out['keywords_keep_assign_move_unassign']);
        self::assertSame('REPORTED', $out['memberships_written']);
    }
}
