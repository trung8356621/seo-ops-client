<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicGroupingRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalHydrator;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposalMapper;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver;

$siteId = (int) ($argv[1] ?? 4);
$run = SeoTopicGroupingRun::query()
    ->where('site_id', $siteId)
    ->where('status', 'proposal_ready')
    ->orderByDesc('id')
    ->first();
$proposal = (new TopicGroupingProposalHydrator)->fromPayload($run->proposal_payload);
$clusters = (new TopicGroupingProposalMapper)->toReclusterClusters($proposal);

$seedMap = [];
$seedCounts = [];
$membersByTopic = [];
foreach (SeoTopicKeyword::query()->where('site_id', $siteId)->get(['keyword_id', 'topic_id', 'is_seed']) as $row) {
    $tid = (int) $row->topic_id;
    $kid = (int) $row->keyword_id;
    $membersByTopic[$tid][$kid] = true;
    if ($row->is_seed) {
        $seedMap[$kid] = $tid;
        $seedCounts[$tid] = ($seedCounts[$tid] ?? 0) + 1;
    }
}
foreach ($clusters as $i => $c) {
    foreach ($c['members'] as $j => $m) {
        if (isset($seedMap[(int) $m['keyword_id']])) {
            $clusters[$i]['members'][$j]['is_seed'] = true;
        }
    }
}
$clusters = (new TopicSeedIdentityResolver)->apply($clusters, $seedMap);
$claimed = [];
foreach ($clusters as $c) {
    if ($c['topic_id'] !== null) {
        $claimed[(int) $c['topic_id']] = true;
    }
}

$topics = SeoTopic::query()
    ->where('site_id', $siteId)
    ->where('source', '!=', TopicSource::MANUAL)
    ->where('is_locked', false)
    ->get(['id', 'name']);

$seededUnclaimed = 0;
$zeroSeedUnclaimed = 0;
foreach ($topics as $t) {
    $tid = (int) $t->id;
    if (isset($claimed[$tid])) {
        continue;
    }
    if (($seedCounts[$tid] ?? 0) > 0) {
        $seededUnclaimed++;
    } else {
        $zeroSeedUnclaimed++;
    }
}
echo 'seed_claimed='.count($claimed)." seeded_unclaimed={$seededUnclaimed} zero_seed_unclaimed={$zeroSeedUnclaimed}\n";

$simulate = static function (array $thr) use ($clusters, $topics, $membersByTopic, $claimed): array {
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
        if ($gSize === 0) {
            continue;
        }
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
            if ($inter < (int) $thr['min_inter']) {
                continue;
            }
            $j = $inter / max(1, $gSize + $tSize - $inter);
            $ec = $inter / $tSize;
            $pc = $inter / $gSize;
            $ok = $j >= $thr['min_j']
                || ($ec >= $thr['min_ec'] && $pc >= $thr['min_pc'])
                || ($ec >= $thr['min_ec_strong'] && $inter >= 2);
            if (! $ok) {
                continue;
            }
            $pairs[] = compact('gi', 'tid', 'j', 'inter', 'ec', 'pc');
        }
    }
    usort($pairs, static fn ($a, $b) => [$b['j'], $b['inter'], $b['ec'], $a['tid']] <=> [$a['j'], $a['inter'], $a['ec'], $b['tid']]);
    $usedG = [];
    $usedT = $claimed;
    $reuse = count($claimed);
    foreach ($pairs as $p) {
        if (isset($usedG[$p['gi']]) || isset($usedT[$p['tid']])) {
            continue;
        }
        $usedG[$p['gi']] = true;
        $usedT[$p['tid']] = true;
        $reuse++;
    }
    $dissolve = 0;
    foreach ($topics as $t) {
        if (! isset($usedT[(int) $t->id])) {
            $dissolve++;
        }
    }

    return [
        'pairs' => count($pairs),
        'reuse' => $reuse,
        'create' => count($clusters) - $reuse,
        'dissolve' => $dissolve,
    ];
};

foreach ([
    ['min_j' => 0.20, 'min_ec' => 0.35, 'min_pc' => 0.25, 'min_inter' => 2, 'min_ec_strong' => 0.55],
    ['min_j' => 0.15, 'min_ec' => 0.30, 'min_pc' => 0.20, 'min_inter' => 2, 'min_ec_strong' => 0.50],
    ['min_j' => 0.12, 'min_ec' => 0.25, 'min_pc' => 0.20, 'min_inter' => 2, 'min_ec_strong' => 0.45],
    ['min_j' => 0.10, 'min_ec' => 0.40, 'min_pc' => 0.15, 'min_inter' => 2, 'min_ec_strong' => 0.40],
] as $thr) {
    $r = $simulate($thr);
    echo 'thr='.json_encode($thr).' => '.json_encode($r)."\n";
}

// Best existing_coverage for unclaimed topics
$byEc = [];
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
    $best = null;
    foreach ($clusters as $gi => $cluster) {
        if ($cluster['topic_id'] !== null) {
            continue;
        }
        $gSet = [];
        foreach ($cluster['members'] as $m) {
            $gSet[(int) $m['keyword_id']] = true;
        }
        $gSize = count($gSet);
        if ($gSize === 0) {
            continue;
        }
        $inter = 0;
        foreach ($tSet as $kid => $_) {
            if (isset($gSet[$kid])) {
                $inter++;
            }
        }
        if ($inter === 0) {
            continue;
        }
        $ec = $inter / $tSize;
        $pc = $inter / $gSize;
        $j = $inter / max(1, $gSize + $tSize - $inter);
        if ($best === null || $ec > $best['ec'] || ($ec === $best['ec'] && $inter > $best['inter'])) {
            $best = [
                'gi' => $gi,
                'ec' => round($ec, 4),
                'pc' => round($pc, 4),
                'j' => round($j, 4),
                'inter' => $inter,
                'tSize' => $tSize,
                'gSize' => $gSize,
                'name' => (string) $topic->name,
                'gname' => $cluster['name'],
                'seeded' => ($seedCounts[$tid] ?? 0) > 0,
            ];
        }
    }
    if ($best !== null) {
        $byEc[] = $best;
    }
}
usort($byEc, static fn ($a, $b) => $b['ec'] <=> $a['ec']);
$dist = ['ge50' => 0, 'ge35' => 0, 'ge25' => 0, 'ge15' => 0, 'lt15' => 0];
foreach ($byEc as $b) {
    if ($b['ec'] >= 0.5) {
        $dist['ge50']++;
    } elseif ($b['ec'] >= 0.35) {
        $dist['ge35']++;
    } elseif ($b['ec'] >= 0.25) {
        $dist['ge25']++;
    } elseif ($b['ec'] >= 0.15) {
        $dist['ge15']++;
    } else {
        $dist['lt15']++;
    }
}
echo 'unclaimed_best_ec_dist='.json_encode($dist).' n='.count($byEc)."\n";
echo "top continuity:\n";
foreach (array_slice($byEc, 0, 15) as $b) {
    $seed = $b['seeded'] ? 'seeded' : 'discovered';
    echo "  ec={$b['ec']} pc={$b['pc']} j={$b['j']} inter={$b['inter']}/{$b['tSize']} g={$b['gSize']} [{$seed}] {$b['name']} => {$b['gname']}\n";
}
