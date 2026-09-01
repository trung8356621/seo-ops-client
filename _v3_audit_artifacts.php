<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use Illuminate\Support\Facades\DB;

$db = DB::connection('omi_seo_ai');
$patterns = ['V3-ACCEPT-%', 'V3-CATCHUP-%', 'V3-TEST-%', 'V3-TMP-%', 'V3-PROBE-%', '%[V3-CATCHUP-TEST]%'];

$rows = $db->table('articles as a')
    ->leftJoin('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
    ->leftJoin('article_meta as am_pt', function ($j): void {
        $j->on('am_pt.article_id', '=', 'a.id')->where('am_pt.meta_key', '=', 'wp_post_type');
    })
    ->where('a.site_id', 2)
    ->whereNull('a.deleted_at')
    ->where(function ($q) use ($patterns): void {
        foreach ($patterns as $p) {
            $q->orWhere('a.title', 'like', $p);
        }
    })
    ->orderBy('a.id')
    ->get([
        'a.id as article_id',
        'a.title',
        'a.slug',
        'a.status',
        'a.created_at',
        'wal.wp_post_id',
        'am_pt.meta_value as wp_post_type',
    ]);

echo "count=".$rows->count()."\n";
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}

// Also catch title updates that include catchup marker
$extra = $db->table('articles as a')
    ->leftJoin('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
    ->where('a.site_id', 2)
    ->whereNull('a.deleted_at')
    ->where('a.title', 'like', '%V3-CATCHUP-TEST%')
    ->orderBy('a.id')
    ->get(['a.id as article_id', 'a.title', 'a.status', 'wal.wp_post_id', 'a.created_at']);
echo "catchup_title_mutations=".$extra->count()."\n";
foreach ($extra as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}

// System-like wp_post_types
$sys = $db->table('articles as a')
    ->join('article_meta as am', function ($j): void {
        $j->on('am.article_id', '=', 'a.id')->where('am.meta_key', '=', 'wp_post_type');
    })
    ->leftJoin('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
    ->where('a.site_id', 2)
    ->whereNull('a.deleted_at')
    ->whereNotIn(DB::raw('LOWER(TRIM(am.meta_value))'), ['post', 'page', 'product'])
    ->selectRaw('am.meta_value as wp_post_type, COUNT(*) as c')
    ->groupBy('am.meta_value')
    ->get();
echo "non_standard_wp_post_types:\n";
foreach ($sys as $r) {
    echo "  {$r->wp_post_type} = {$r->c}\n";
}
