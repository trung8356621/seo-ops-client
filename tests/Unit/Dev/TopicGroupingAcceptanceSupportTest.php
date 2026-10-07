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

    public function test_locked_membership_comparison(): void
    {
        $before = [
            ['keyword_id' => 1, 'topic_id' => 9, 'is_locked' => true],
            ['keyword_id' => 2, 'topic_id' => 9, 'is_locked' => false],
        ];
        $afterOk = [
            ['keyword_id' => 1, 'topic_id' => 12, 'is_locked' => true],
            ['keyword_id' => 2, 'topic_id' => 12, 'is_locked' => false],
        ];
        $afterBad = [
            ['keyword_id' => 1, 'topic_id' => 12, 'is_locked' => false],
        ];
        self::assertSame([], \TopicGroupingAcceptanceSupport::missingLockedMembershipKeywordIds($before, $afterOk));
        self::assertSame([1], \TopicGroupingAcceptanceSupport::missingLockedMembershipKeywordIds($before, $afterBad));
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
