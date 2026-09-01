<?php

declare(strict_types=1);

/**
 * One-shot cleanup: V3 acceptance fixtures for site_id=2.
 * Restores mutated real page (wp_id=11); trashes WP fixtures; soft-deletes Laravel rows + relations.
 *
 * php _v3_cleanup_artifacts.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;

const SITE_ID = 2;
const RESTORE_WP_ID = 11;
const RESTORE_TITLE = 'Giới thiệu';

function out(string $msg): void
{
    echo '['.date('H:i:s').'] '.$msg.PHP_EOL;
}

function seoDb()
{
    return DB::connection('omi_seo_ai');
}

$site = Site::query()->find(SITE_ID);
if ($site === null) {
    fwrite(STDERR, "Site missing\n");
    exit(1);
}

$domain = trim((string) $site->domain);
$base = preg_match('#^https?://#i', $domain) ? rtrim($domain, '/') : 'https://'.ltrim($domain, '/');
$writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));

$patterns = ['V3-ACCEPT-%', 'V3-CATCHUP-%', 'V3-TEST-%', 'V3-TMP-%', 'V3-PROBE-%'];

$fixtures = seoDb()->table('articles as a')
    ->leftJoin('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
    ->where('a.site_id', SITE_ID)
    ->whereNull('a.deleted_at')
    ->where(function ($q) use ($patterns): void {
        foreach ($patterns as $p) {
            $q->orWhere('a.title', 'like', $p);
        }
        $q->orWhere('a.title', 'like', '%[V3-CATCHUP-TEST]%')
            ->orWhere('a.title', 'like', '%V3-CATCHUP-TEST%');
    })
    ->get([
        'a.id as article_id',
        'a.title',
        'a.slug',
        'a.status',
        'a.created_at',
        'wal.wp_post_id',
    ]);

$report = [
    'audit' => [],
    'restore' => null,
    'wp_trashed' => [],
    'laravel_soft_deleted' => [],
    'relations_cleaned' => [],
    'skipped_business' => [],
];

foreach ($fixtures as $row) {
    $report['audit'][] = [
        'article_id' => (int) $row->article_id,
        'wp_post_id' => $row->wp_post_id !== null ? (int) $row->wp_post_id : null,
        'title' => (string) $row->title,
        'slug' => $row->slug,
        'status' => $row->status,
        'created_at' => (string) $row->created_at,
    ];
}

out('AUDIT count='.count($report['audit']));

// --- Restore real page (wp 11 / article 964) ---
$restoreRow = $fixtures->first(static fn ($r): bool => (int) ($r->wp_post_id ?? 0) === RESTORE_WP_ID);
if ($restoreRow !== null) {
    out('RESTORE WP '.RESTORE_WP_ID.' title → '.RESTORE_TITLE);
    $resp = Http::timeout(60)->acceptJson()->withToken($writeToken)
        ->post($base.'/wp-json/omi-seo-ai/v1/posts/'.RESTORE_WP_ID.'/editor-sync', [
            'title' => RESTORE_TITLE,
        ]);
    $article = SeoArticle::query()->find((int) $restoreRow->article_id);
    if ($article !== null) {
        $article->title = RESTORE_TITLE;
        $article->save();
    }
    $report['restore'] = [
        'wp_post_id' => RESTORE_WP_ID,
        'article_id' => (int) $restoreRow->article_id,
        'http' => $resp->status(),
        'title' => RESTORE_TITLE,
        'body' => $resp->json(),
    ];
    $report['skipped_business'][] = (int) $restoreRow->article_id;
}

$deleteTargets = $fixtures->filter(static function ($r) use ($restoreRow): bool {
    if ($restoreRow !== null && (int) $r->article_id === (int) $restoreRow->article_id) {
        return false;
    }
    // Only delete clearly fixture-titled posts (prefix), never mutated real content.
    $title = (string) $r->title;

    return str_starts_with($title, 'V3-ACCEPT-')
        || str_starts_with($title, 'V3-TMP-')
        || str_starts_with($title, 'V3-PROBE-')
        || str_starts_with($title, 'V3-TEST-')
        || str_starts_with($title, 'V3-CATCHUP-');
});

foreach ($deleteTargets as $row) {
    $wpId = (int) ($row->wp_post_id ?? 0);
    $articleId = (int) $row->article_id;
    out("CLEAN fixture article={$articleId} wp={$wpId} title={$row->title}");

    if ($wpId > 0 && $writeToken !== '') {
        $resp = Http::timeout(60)->acceptJson()->withToken($writeToken)
            ->post($base.'/wp-json/omi-seo-ai/v1/posts/'.$wpId.'/editor-sync', [
                'status' => 'trash',
            ]);
        $report['wp_trashed'][] = [
            'wp_post_id' => $wpId,
            'http' => $resp->status(),
            'status' => is_array($resp->json()) ? ($resp->json()['status'] ?? null) : null,
        ];
        out("  WP trash {$wpId} HTTP {$resp->status()}");
    }

    $article = SeoArticle::query()->find($articleId);
    if ($article !== null && ! $article->trashed()) {
        $article->delete();
        $report['laravel_soft_deleted'][] = $articleId;
    }

    $cleaned = ['article_id' => $articleId];
    if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
        $cleaned['seo_link_maps'] = seoDb()->table('seo_link_maps')
            ->where(function ($q) use ($articleId): void {
                $q->where('source_article_id', $articleId)
                    ->orWhere('target_article_id', $articleId);
            })
            ->delete();
    }
    if (Schema::connection('omi_seo_ai')->hasTable('article_meta')) {
        $cleaned['article_meta'] = seoDb()->table('article_meta')
            ->where('article_id', $articleId)
            ->delete();
    }
    if (Schema::connection('omi_seo_ai')->hasTable('article_keyword')) {
        $cleaned['article_keyword'] = seoDb()->table('article_keyword')
            ->where('article_id', $articleId)
            ->delete();
    }
    if (Schema::connection('omi_seo_ai')->hasTable('seo_article_scores')) {
        $cleaned['seo_article_scores'] = seoDb()->table('seo_article_scores')
            ->where('article_id', $articleId)
            ->delete();
    }
    $report['relations_cleaned'][] = $cleaned;
}

// Reconcile check: WP discover vs Laravel after soft-delete
$client = app(WordPressSiteSyncV3Client::class);
$discover = $client->discover($site);
$wpTotal = (int) (($discover['discover']['total'] ?? 0));
$laravelWp = seoDb()->table('wordpress_article_links as wal')
    ->join('articles as a', 'a.id', '=', 'wal.article_id')
    ->where('wal.site_id', SITE_ID)
    ->where('wal.wp_post_id', '>', 0)
    ->whereNull('a.deleted_at')
    ->where('a.status', '!=', 'trash')
    ->count();

$report['reconcile'] = [
    'wp_discover_total' => $wpTotal,
    'laravel_wp_backed_active' => $laravelWp,
    'discover_ok' => (bool) ($discover['success'] ?? false),
];

$path = __DIR__.'/_v3_cleanup_artifacts_report.json';
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
out('REPORT '.$path);
out('trashed_wp='.count($report['wp_trashed']).' soft_deleted='.count($report['laravel_soft_deleted']));
out('reconcile wp_total='.$wpTotal.' laravel='.$laravelWp);
