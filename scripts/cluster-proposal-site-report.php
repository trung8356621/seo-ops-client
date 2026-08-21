<?php

declare(strict_types=1);

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$siteId = (int) ($argv[1] ?? 5);
$strategy = (string) ($argv[2] ?? KeywordClusterProposalStrategy::BALANCED);

/** @var KeywordClusterProposalEngine $engine */
$engine = app(KeywordClusterProposalEngine::class);
$result = $engine->previewForSite($siteId, $strategy);
$diag = $result->diagnostics;

echo "=== SITE {$siteId} PHASE 1.8 PREVIEW ({$strategy}) ===\n\n";
echo 'Candidates: '.$result->candidateCount."\n";
echo 'Initial clusters: '.($diag['initial_cluster_count'] ?? 0)."\n";
echo 'Quality split count: '.($diag['clusters_split_count'] ?? 0)."\n";
echo 'Subgroups rehomed: '.($diag['subgroups_rehomed'] ?? 0)."\n";
echo 'Competitive member moves: '.($diag['competitive_moves'] ?? 0)."\n";
echo 'Strong duplicate merges: '.($diag['strong_duplicate_merges'] ?? 0)."\n";
echo 'Potential duplicate pairs: '.($diag['potential_duplicate_count'] ?? 0)."\n";
echo 'Members released: '.($diag['members_released'] ?? 0)."\n";
echo 'Final clusters: '.count($result->proposedClusters)."\n";
echo 'READY: '.($diag['ready_proposal_count'] ?? 0)."\n";
echo 'NEEDS_REVIEW: '.($diag['needs_review_proposal_count'] ?? 0)."\n";
echo 'Keywords proposed: '.$result->proposedKeywordCount."\n";
echo 'Unclustered: '.count($result->unclustered)."\n";
echo 'Conservation: '.($result->proposedKeywordCount + count($result->unclustered)).' / '.$result->candidateCount."\n";

$largest = $result->proposedClusters[0] ?? null;
if ($largest !== null) {
    echo 'Largest final: '.$largest->representativeLabel.' | '.$largest->memberCount
        .' | '.($largest->quality?->qualityState ?? '-').' | '.$largest->finalStatus."\n";
}
echo "\n";

echo "=== TOP 15 ===\n";
foreach (array_slice($result->proposedClusters, 0, 15) as $cluster) {
    $q = $cluster->quality;
    echo sprintf(
        "%s | %d | avg %.3f | p25 %.3f | min %.3f | %s | %s\n",
        $cluster->representativeLabel,
        $cluster->memberCount,
        $cluster->cohesion,
        $q?->p25Similarity ?? 0,
        $cluster->minSimilarity,
        $q?->qualityState ?? '-',
        $cluster->finalStatus,
    );
    if ($cluster->rehomeNote) {
        echo '  provenance: '.$cluster->rehomeNote."\n";
    }
}

echo "\n=== EXACT LINEAGE (mega-59) ===\n";
$lineages = $diag['lineage_disposition']['lineages'] ?? [];
$megaLineage = null;
foreach ($lineages as $lineage) {
    if (($lineage['initial_count'] ?? 0) >= 50) {
        $megaLineage = $lineage;
        break;
    }
}
if ($megaLineage === null) {
    usort($lineages, static fn ($a, $b) => ($b['initial_count'] ?? 0) <=> ($a['initial_count'] ?? 0));
    $megaLineage = $lineages[0] ?? null;
}

if ($megaLineage !== null) {
    echo 'Initial lineage: '.($megaLineage['initial_label'] ?? '?')."\n";
    echo 'Initial members: '.($megaLineage['initial_count'] ?? 0)."\n\n";
    echo "Final destinations:\n";
    foreach ($megaLineage['destinations'] ?? [] as $dest) {
        echo sprintf("  %-40s %d\n", $dest['label'], $dest['count']);
    }
    echo sprintf("  %-40s %d\n", 'Released', $megaLineage['released'] ?? 0);
    echo sprintf("  %-40s %d\n", 'TOTAL', $megaLineage['total'] ?? 0);
    echo 'Conserved: '.(($megaLineage['conserved'] ?? false) ? 'YES' : 'NO')."\n";
} else {
    echo "No mega lineage found.\n";
}

echo "\n=== COMPETITIVE MOVE LOG ===\n";
foreach (($diag['competitive_review_log'] ?? $diag['competitive_move_log'] ?? []) as $move) {
    echo ($move['phrase'] ?? '?')
        .' | core_fit '.($move['current_fit'] ?? 0)
        .' | target '.($move['to_label'] ?? '?')
        .' | target_fit '.($move['target_fit'] ?? 0)
        .' | margin '.($move['margin'] ?? 0)
        .' | '.($move['decision'] ?? '')
        ."\n";
}

echo "\n=== RESIDUAL TOPIC REVIEW ===\n";
$topics = [
    'dây rút' => 'drawstring',
    'quảng cáo' => 'advertising',
    'siêu thị' => 'supermarket',
];
foreach ($topics as $needle => $label) {
    echo "\n-- {$label} --\n";
    foreach (($diag['competitive_review_log'] ?? $diag['competitive_move_log'] ?? []) as $move) {
        if (mb_stripos((string) ($move['phrase'] ?? ''), $needle) !== false) {
            echo ($move['phrase'] ?? '?')
                .' | core_fit '.($move['current_fit'] ?? 0)
                .' | target '.($move['to_label'] ?? '?')
                .' | target_fit '.($move['target_fit'] ?? 0)
                .' | margin '.($move['margin'] ?? 0)
                .' | '.($move['decision'] ?? '')
                ."\n";
        }
    }
}

echo "\n=== XƯỞNG MAY REGRESSION ===\n";
foreach ($result->proposedClusters as $cluster) {
    if (mb_stripos($cluster->representativeLabel, 'xưởng may') === false) {
        continue;
    }
    echo 'UNCHANGED stable | '.$cluster->representativeLabel.' | '.$cluster->memberCount;
    if ($cluster->rehomeNote) {
        echo ' | '.$cluster->rehomeNote;
    }
    echo "\n";
}

echo "\n=== STRONG MERGE LOG ===\n";
foreach (($diag['strong_merge_log'] ?? []) as $merge) {
    echo ($merge['left_label'] ?? '?').' <> '.($merge['right_label'] ?? '?')
        .' | medoid '.($merge['medoid_similarity'] ?? 0)
        .' | cross '.($merge['cross_average'] ?? 0)
        .' | merged_p25 '.($merge['merged_p25'] ?? 0)
        .' | '.($merge['classification'] ?? '')
        .' | '.($merge['merge_decision'] ?? '')
        ."\n";
}

echo "\n=== DUPLICATE PAIRS (potential) ===\n";
foreach (($diag['potential_duplicate_pairs'] ?? []) as $pair) {
    echo ($pair['left_label'] ?? '?').' <> '.($pair['right_label'] ?? '?')
        .' | medoid '.($pair['medoid_similarity'] ?? 0)
        .' | '.($pair['classification'] ?? $pair['decision'] ?? '')
        ."\n";
}

echo "\n=== NEEDS_REVIEW REASONS (top) ===\n";
foreach (array_slice($result->proposedClusters, 0, 15) as $cluster) {
    if ($cluster->finalStatus !== 'NEEDS_REVIEW') {
        continue;
    }
    $reasons = [];
    $q = $cluster->quality;
    if ($q?->qualityState === 'MEGA') {
        $reasons[] = 'mega_low_cohesion';
    }
    if ($q !== null && $q->borderlineMemberCount / max(1, $q->memberCount) > 0.35) {
        $reasons[] = 'high_borderline_ratio';
    }
    if ($reasons === []) {
        $reasons[] = 'mixed_subtopic';
    }
    echo $cluster->representativeLabel.' | '.implode(', ', $reasons)."\n";
}

$allConserved = (bool) ($diag['lineage_disposition']['all_conserved'] ?? false);
$globalConserved = $result->proposedKeywordCount + count($result->unclustered) === $result->candidateCount;
$pass = $allConserved && $globalConserved;

echo "\n=== PHASE 2 READINESS ===\n";
echo $pass ? "PASS FOR APPLY DESIGN\n" : "NEEDS MORE TUNING\n";
echo 'Lineage conserved: '.($allConserved ? 'YES' : 'NO')."\n";
echo 'Global conserved: '.($globalConserved ? 'YES' : 'NO')."\n";
echo "cluster_key writes: ZERO (preview only)\n";
echo "\nDONE\n";
