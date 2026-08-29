<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReconcileFocusArticleTopicsService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\UpdateClusterCanonicalService;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (class_exists(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)) {
    app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
        ->bootstrapLegacySharedConnection();
}

$siteId = 5;
$clusterKey = 'tui_vai_khong_det__e3b0c4';
$phrases = [
    'đặt may túi vải không dệt',
    'sản xuất túi vải không dệt',
    'sản xuất túi vải không dệt tại TP.HCM',
    'Túi Vải Không Dệt Ép Nhiệt hay May Chỉ',
    'Túi Vải Không Dệt Kiểu May Viền',
    'túi vải không dệt may viền',
    'Xưởng May Túi Vải Không Dệt',
    'Xưởng May Túi Vải Không Dệt Giá Sỉ',
    'xưởng sản xuất túi vải không dệt',
];

echo "BEFORE reconcileMembership\n";
foreach ($phrases as $p) {
    $row = DB::connection('omi_seo_ai')->table('keywords as k')
        ->leftJoin('seo_keyword_classifications as c', 'c.keyword_id', '=', 'k.id')
        ->whereRaw('LOWER(k.phrase) = ?', [mb_strtolower($p)])
        ->first(['k.id', 'k.phrase', 'c.cluster_key']);
    if (! $row) {
        // try case-insensitive like
        $row = DB::connection('omi_seo_ai')->table('keywords as k')
            ->leftJoin('seo_keyword_classifications as c', 'c.keyword_id', '=', 'k.id')
            ->where('k.phrase', $p)
            ->first(['k.id', 'k.phrase', 'c.cluster_key']);
    }
    if (! $row) {
        echo "  MISSING_IN_DB: {$p}\n";
        continue;
    }
    echo sprintf("  id=%d | %s | cluster=%s\n", (int) $row->id, $row->phrase, $row->cluster_key ?? 'NULL');
}

$result = app(UpdateClusterCanonicalService::class)->reconcileMembership($siteId, $clusterKey);
echo "\nRESULT\n".json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";

echo "\nAFTER reconcileMembership\n";
foreach ($phrases as $p) {
    $row = DB::connection('omi_seo_ai')->table('keywords as k')
        ->leftJoin('seo_keyword_classifications as c', 'c.keyword_id', '=', 'k.id')
        ->where('k.phrase', $p)
        ->first(['k.id', 'k.phrase', 'c.cluster_key']);
    if (! $row) {
        echo "  MISSING_IN_DB: {$p}\n";
        continue;
    }
    $meta = DB::connection('omi_seo_ai')->table('seo_topic_cluster_meta')
        ->where('site_id', $siteId)
        ->where('cluster_key', (string) ($row->cluster_key ?? ''))
        ->value('canonical_phrase');
    echo sprintf(
        "  id=%d | %s | cluster=%s | topic=%s\n",
        (int) $row->id,
        $row->phrase,
        $row->cluster_key ?? 'NULL',
        $meta ?? '',
    );
}

$orphans = app(ReconcileFocusArticleTopicsService::class)->loadOrphanFocusKeywords($siteId);
echo "\nORPHAN_FOCUS_COUNT=".count($orphans)."\n";

// Dictionary-style: unassigned + strong core
$canonNorm = 'tui vai khong det';
$still = DB::connection('omi_seo_ai')->select(
    "SELECT k.id, k.phrase, c.cluster_key,
            (SELECT COUNT(*) FROM keyword_meta km WHERE km.keyword_id=k.id AND km.meta_key=?) as focus_n
     FROM keywords k
     INNER JOIN seo_keyword_classifications c ON c.keyword_id=k.id
     WHERE (c.cluster_key IS NULL OR c.cluster_key='')
       AND c.is_seo_keyword=1
       AND (LOWER(k.phrase) LIKE '%túi vải không dệt%' OR LOWER(k.phrase) LIKE '%tui vai khong det%')
     ORDER BY k.phrase",
    [KeywordMetaKey::MainArticleId->value],
);
echo 'STILL_UNASSIGNED_CONTAINING_CORE='.count($still)."\n";
foreach ($still as $s) {
    echo sprintf("  id=%d | %s | focus=%d\n", (int) $s->id, $s->phrase, (int) $s->focus_n);
}
