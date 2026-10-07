<?php

declare(strict_types=1);

/**
 * READ-ONLY: membership overlap matrix for site Topic identity calibration.
 * php scripts/dev/topic-identity-overlap-matrix.php [siteId]
 */

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalHydrator;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalMapper;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDiscoveredIdentityResolver;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$siteId = (int) ($argv[1] ?? 4);
$run = SeoTopicGroupingRun::query()
    ->where('site_id', $siteId)
    ->where('status', 'proposal_ready')
    ->orderByDesc('id')
    ->first();
if ($run === null || ! is_array($run->proposal_payload)) {
    fwrite(STDERR, "no proposal_ready\n");
    exit(2);
}

$proposal = (new TopicGroupingProposalHydrator)->fromPayload($run->proposal_payload);
$clusters = (new TopicGroupingProposalMapper)->toReclusterClusters($proposal);

$seedMap = [];
foreach (SeoTopicKeyword::query()->where('site_id', $siteId)->where('is_seed', true)->get(['keyword_id', 'topic_id']) as $row) {
    $seedMap[(int) $row->keyword_id] = (int) $row->topic_id;
}
foreach ($clusters as $i => $c) {
    foreach ($c['members'] as $j => $m) {
        if (isset($seedMap[(int) $m['keyword_id']])) {
            $clusters[$i]['members'][$j]['is_seed'] = true;
        }
    }
}
$clusters = (new TopicSeedIdentityResolver)->apply($clusters, $seedMap);

$topics = SeoTopic::query()->where('site_id', $siteId)->where('source', '!=', TopicSource::MANUAL)->where('is_locked', false)->get(['id', 'name']);
$membersByTopic = [];
foreach (SeoTopicKeyword::query()->where('site_id', $siteId)->get(['topic_id', 'keyword_id']) as $row) {
    $membersByTopic[(int) $row->topic_id][(int) $row->keyword_id] = true;
}

$claimed = [];
foreach ($clusters as $c) {
    if ($c['topic_id'] !== null) {
        $claimed[(int) $c['topic_id']] = true;
    }
}

$bestByGroup = [];
$bestByTopic = [];
$bucket = ['strong' => 0, 'good' => 0, 'weak' => 0, 'none' => 0];
$jaccards = [];

foreach ($clusters as $gi => $cluster) {
    if ($cluster['topic_id'] !== null) {
        continue; // already seed-resolved
    }
    $gSet = [];
    foreach ($cluster['members'] as $m) {
        $gSet[(int) $m['keyword_id']] = true;
    }
    $gSize = count($gSet);
    if ($gSize === 0) {
        $bucket['none']++;
        continue;
    }
    $best = null;
    foreach ($topics as $topic) {
        $tid = (int) $topic->id;
        if (isset($claimed[$tid])) {
            continue;
        }
        $tSet = $membersByTopic[$tid] ?? [];
        $tSize = count($tSet);
        if ($tSize === 0) {
            continue;
        }
        $inter = 0;
        foreach ($gSet as $kid => $_) {
            if (isset($tSet[$kid])) {
                $inter++;
            }
        }
        if ($inter === 0) {
            continue;
        }
        $union = $gSize + $tSize - $inter;
        $j = $inter / max(1, $union);
        $ec = $inter / $tSize;
        $pc = $inter / $gSize;
        $cand = compact('tid', 'inter', 'j', 'ec', 'pc', 'tSize', 'gSize') + ['name' => (string) $topic->name, 'gname' => $cluster['name']];
        if ($best === null || $j > $best['j'] || ($j === $best['j'] && $inter > $best['inter'])) {
            $best = $cand;
        }
        if (! isset($bestByTopic[$tid]) || $j > $bestByTopic[$tid]['j']) {
            $bestByTopic[$tid] = $cand + ['gi' => $gi];
        }
    }
    if ($best === null) {
        $bucket['none']++;
        continue;
    }
    $bestByGroup[$gi] = $best;
    $jaccards[] = $best['j'];
    if ($best['j'] >= 0.5 || ($best['ec'] >= 0.5 && $best['pc'] >= 0.4)) {
        $bucket['strong']++;
    } elseif ($best['j'] >= 0.25 || ($best['ec'] >= 0.35 && $best['pc'] >= 0.25)) {
        $bucket['good']++;
    } elseif ($best['j'] >= 0.1 || $best['inter'] >= 3) {
        $bucket['weak']++;
    } else {
        $bucket['none']++;
    }
}

sort($jaccards);
$n = count($jaccards);
$pct = static function (array $arr, float $p) use ($n): float {
    if ($n === 0) {
        return 0.0;
    }
    $i = (int) floor(($n - 1) * $p);

    return round($arr[$i], 4);
};

$seedReused = count($claimed);
echo "run_id={$run->id} groups=".count($clusters)." topics=".$topics->count()." seed_claimed={$seedReused}\n";
echo 'unresolved_groups='.(count($clusters) - $seedReused)."\n";
echo 'best_match_buckets='.json_encode($bucket)."\n";
echo 'jaccard_p25='.$pct($jaccards, 0.25).' p50='.$pct($jaccards, 0.5).' p75='.$pct($jaccards, 0.75).' p90='.$pct($jaccards, 0.9)."\n";

// Simulate greedy match at candidate thresholds
foreach ([
    ['min_j' => 0.35, 'min_ec' => 0.45, 'min_pc' => 0.35, 'min_inter' => 2],
    ['min_j' => 0.25, 'min_ec' => 0.40, 'min_pc' => 0.30, 'min_inter' => 2],
    ['min_j' => 0.20, 'min_ec' => 0.35, 'min_pc' => 0.25, 'min_inter' => 2],
    ['min_j' => 0.15, 'min_ec' => 0.30, 'min_pc' => 0.20, 'min_inter' => 3],
] as $thr) {
    $pairs = [];
    foreach ($clusters as $gi => $cluster) {
        if ($cluster['topic_id'] !== null) {
            continue;
        }
        $gSet = [];
        foreach ($cluster['members'] as $m) {
            $gSet[(int) $m['keyword_id']] = true;
        }
        $gSize = count($gSet);
        foreach ($topics as $topic) {
            $tid = (int) $topic->id;
            if (isset($claimed[$tid])) {
                continue;
            }
            $tSet = $membersByTopic[$tid] ?? [];
            $tSize = count($tSet);
            if ($tSize === 0 || $gSize === 0) {
                continue;
            }
            $inter = 0;
            foreach ($gSet as $kid => $_) {
                if (isset($tSet[$kid])) {
                    $inter++;
                }
            }
            if ($inter < $thr['min_inter']) {
                continue;
            }
            $union = $gSize + $tSize - $inter;
            $j = $inter / max(1, $union);
            $ec = $inter / $tSize;
            $pc = $inter / $gSize;
            $ok = $j >= $thr['min_j'] || ($ec >= $thr['min_ec'] && $pc >= $thr['min_pc']);
            if (! $ok) {
                continue;
            }
            $pairs[] = ['gi' => $gi, 'tid' => $tid, 'j' => $j, 'inter' => $inter, 'ec' => $ec, 'pc' => $pc];
        }
    }
    usort($pairs, static fn ($a, $b) => [$b['j'], $b['inter'], $b['ec'], $a['tid']] <=> [$a['j'], $a['inter'], $a['ec'], $b['tid']]);
    $usedG = $claimed;
    $usedT = $claimed;
    $reuse = count($claimed);
    foreach ($pairs as $p) {
        if (isset($usedG['g'.$p['gi']]) || isset($usedT[$p['tid']])) {
            continue;
        }
        $usedG['g'.$p['gi']] = true;
        $usedT[$p['tid']] = true;
        $reuse++;
    }
    $new = count($clusters) - $reuse;
    $dissolve = $topics->count() - (count($usedT));
    // usedT includes seed claimed ids; dissolve = topics not in usedT
    $dissolve = 0;
    foreach ($topics as $topic) {
        if (! isset($usedT[(int) $topic->id])) {
            $dissolve++;
        }
    }
    echo 'thr='.json_encode($thr)." => reuse={$reuse} create≈{$new} dissolve≈{$dissolve} pairs=".count($pairs)."\n";
}

echo "sample_strong:\n";
$shown = 0;
foreach ($bestByGroup as $gi => $b) {
    if ($b['j'] < 0.35 && ! ($b['ec'] >= 0.5 && $b['pc'] >= 0.4)) {
        continue;
    }
    echo "  j={$b['j']} ec={$b['ec']} pc={$b['pc']} inter={$b['inter']} topic={$b['tid']}:{$b['name']} <= {$b['gname']}\n";
    if (++$shown >= 8) {
        break;
    }
}
