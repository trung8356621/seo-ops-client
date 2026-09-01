<?php

declare(strict_types=1);

/**
 * Follow-up: classify Laravel posts excluded by Posts UI for site 3.
 * php _audit_site3_posts_ui_exclusion.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\Seo\Services\FocusKeywordCoverageQuery;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;

const SITE_ID = 3;

$db = DB::connection('omi_seo_ai');
$focusQuery = new FocusKeywordCoverageQuery();

$inventory = $db->table('articles as a')
    ->leftJoin('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
    ->leftJoin('article_meta as ct', function ($join): void {
        $join->on('ct.article_id', '=', 'a.id')->where('ct.meta_key', '=', 'content_type');
    })
    ->leftJoin('article_meta as wpt', function ($join): void {
        $join->on('wpt.article_id', '=', 'a.id')->where('wpt.meta_key', '=', 'wp_post_type');
    })
    ->leftJoin('article_meta as wit', function ($join): void {
        $join->on('wit.article_id', '=', 'a.id')->where('wit.meta_key', '=', 'wp_is_term');
    })
    ->where('a.site_id', SITE_ID)
    ->whereNull('a.deleted_at')
    ->where(function ($query): void {
        $query->whereNull('wit.meta_value')
            ->orWhereRaw("LOWER(TRIM(wit.meta_value)) NOT IN ('1','true','yes')");
    })
    ->where(function ($query): void {
        $query->where('ct.meta_value', 'post')
            ->orWhere(function ($legacy): void {
                $legacy->where(function ($missing): void {
                    $missing->whereNull('ct.meta_value')->orWhereRaw("TRIM(ct.meta_value) = ''");
                })->where('wpt.meta_value', 'post');
            });
    })
    ->select([
        'a.id as article_id',
        'a.title',
        'a.status',
        'a.language',
        'a.review_status',
        'wal.wp_post_id',
        'ct.meta_value as content_type',
        'wpt.meta_value as wp_post_type',
    ])
    ->get();

$inventoryIds = $inventory->pluck('article_id')->map(static fn ($id): int => (int) $id)->all();
$byArticle = [];
foreach ($inventory as $row) {
    $byArticle[(int) $row->article_id] = $row;
}

$uiQuery = SeoArticle::query()->where('articles.site_id', SITE_ID)->whereIn('articles.id', $inventoryIds);
ArticleResource::applyContentTabScope($uiQuery, ListArticles::TAB_POSTS);
ArticleResource::applyExcludeSkipSeoAuditScope($uiQuery);
ArticleResource::applyWpSyncQueueUnreviewedScope($uiQuery);
ArticleResource::applyPostTypeFilterScope($uiQuery, 'post');
$uiIds = $uiQuery->pluck('articles.id')->map(static fn ($id): int => (int) $id)->all();
$uiSet = array_fill_keys($uiIds, true);

$excludedIds = array_values(array_filter($inventoryIds, static fn (int $id): bool => ! isset($uiSet[$id])));
sort($excludedIds);

echo 'inventory='.count($inventoryIds).' ui='.count($uiIds).' excluded='.count($excludedIds).PHP_EOL;

// Layer peel: which scope removes them
$afterTab = SeoArticle::query()->where('articles.site_id', SITE_ID)->whereIn('articles.id', $inventoryIds);
ArticleResource::applyContentTabScope($afterTab, ListArticles::TAB_POSTS);
$afterTabIds = $afterTab->pluck('articles.id')->map(static fn ($id): int => (int) $id)->all();

$afterSkip = SeoArticle::query()->where('articles.site_id', SITE_ID)->whereIn('articles.id', $inventoryIds);
ArticleResource::applyContentTabScope($afterSkip, ListArticles::TAB_POSTS);
ArticleResource::applyExcludeSkipSeoAuditScope($afterSkip);
$afterSkipIds = $afterSkip->pluck('articles.id')->map(static fn ($id): int => (int) $id)->all();

$afterUnreviewed = SeoArticle::query()->where('articles.site_id', SITE_ID)->whereIn('articles.id', $inventoryIds);
ArticleResource::applyContentTabScope($afterUnreviewed, ListArticles::TAB_POSTS);
ArticleResource::applyExcludeSkipSeoAuditScope($afterUnreviewed);
ArticleResource::applyWpSyncQueueUnreviewedScope($afterUnreviewed);
$afterUnreviewedIds = $afterUnreviewed->pluck('articles.id')->map(static fn ($id): int => (int) $id)->all();

$afterType = SeoArticle::query()->where('articles.site_id', SITE_ID)->whereIn('articles.id', $inventoryIds);
ArticleResource::applyContentTabScope($afterType, ListArticles::TAB_POSTS);
ArticleResource::applyExcludeSkipSeoAuditScope($afterType);
ArticleResource::applyWpSyncQueueUnreviewedScope($afterType);
ArticleResource::applyPostTypeFilterScope($afterType, 'post');
$afterTypeIds = $afterType->pluck('articles.id')->map(static fn ($id): int => (int) $id)->all();

echo 'layer inventory='.count($inventoryIds)
    .' after_tab='.count($afterTabIds)
    .' after_skip='.count($afterSkipIds)
    .' after_unreviewed='.count($afterUnreviewedIds)
    .' after_type='.count($afterTypeIds).PHP_EOL;

$metas = $db->table('article_meta')
    ->whereIn('article_id', $excludedIds)
    ->whereIn('meta_key', ['content_type', 'wp_post_type', 'wp_is_term', 'skip_seo_audit'])
    ->get(['article_id', 'meta_key', 'meta_value']);
$metaMap = [];
foreach ($metas as $meta) {
    $metaMap[(int) $meta->article_id][(string) $meta->meta_key] = (string) $meta->meta_value;
}

$effectiveIds = [];
if ($inventoryIds !== []) {
    foreach ($focusQuery->applyHasEffectiveFocusScope(
        SeoArticle::query()->whereIn('articles.id', $inventoryIds)
    )->pluck('articles.id') as $id) {
        $effectiveIds[(int) $id] = true;
    }
}

$providerIds = [];
$providerRows = $db->table('keyword_meta as km')
    ->join('keywords as k', 'k.id', '=', 'km.keyword_id')
    ->where('km.meta_key', KeywordMetaKey::MainArticleId->value)
    ->whereIn('km.meta_value', array_map('strval', $inventoryIds))
    ->where('k.source', SiteSyncSchema::SOURCE_PROVIDER)
    ->whereNotNull('k.phrase')
    ->whereRaw("TRIM(k.phrase) <> ''")
    ->get(['km.meta_value as article_id']);
foreach ($providerRows as $row) {
    $providerIds[(int) $row->article_id] = true;
}

$reasons = [
    'skip_seo_audit' => [],
    'review_status_approved' => [],
    'review_status_archived' => [],
    'is_term' => [],
    'wrong_content_type_filter' => [],
    'other' => [],
];
$details = [];
$excludedWithFocus = [];
$excludedWithoutFocus = [];

foreach ($excludedIds as $articleId) {
    $row = $byArticle[$articleId];
    $meta = $metaMap[$articleId] ?? [];
    $reasonList = [];
    if (in_array(strtolower(trim((string) ($meta['skip_seo_audit'] ?? ''))), ['1', 'true', 'yes'], true)) {
        $reasonList[] = 'skip_seo_audit';
        $reasons['skip_seo_audit'][] = $articleId;
    }
    $review = strtolower(trim((string) ($row->review_status ?? '')));
    if ($review === ArticleReviewStatus::Approved->value) {
        $reasonList[] = 'review_status_approved';
        $reasons['review_status_approved'][] = $articleId;
    }
    if ($review === ArticleReviewStatus::Archived->value) {
        $reasonList[] = 'review_status_archived';
        $reasons['review_status_archived'][] = $articleId;
    }
    if (in_array(strtolower(trim((string) ($meta['wp_is_term'] ?? ''))), ['1', 'true', 'yes'], true)) {
        $reasonList[] = 'is_term';
        $reasons['is_term'][] = $articleId;
    }
    $ct = strtolower(trim((string) ($meta['content_type'] ?? $row->content_type ?? '')));
    $wpt = strtolower(trim((string) ($meta['wp_post_type'] ?? $row->wp_post_type ?? '')));
    // If still excluded after unreviewed+skip but removed by type filter
    $inUnreviewed = in_array($articleId, $afterUnreviewedIds, true);
    $inType = in_array($articleId, $afterTypeIds, true);
    if ($inUnreviewed && ! $inType) {
        $reasonList[] = 'wrong_content_type_filter';
        $reasons['wrong_content_type_filter'][] = $articleId;
    }
    if ($reasonList === []) {
        $reasonList[] = 'other';
        $reasons['other'][] = $articleId;
    }

    $hasFocus = isset($effectiveIds[$articleId]);
    if ($hasFocus) {
        $excludedWithFocus[] = [
            'article_id' => $articleId,
            'wp_post_id' => (int) ($row->wp_post_id ?? 0),
            'title' => mb_substr((string) $row->title, 0, 80),
            'reasons' => $reasonList,
        ];
    } else {
        $excludedWithoutFocus[] = [
            'article_id' => $articleId,
            'wp_post_id' => (int) ($row->wp_post_id ?? 0),
            'title' => mb_substr((string) $row->title, 0, 80),
            'reasons' => $reasonList,
        ];
    }

    $details[] = [
        'article_id' => $articleId,
        'wp_post_id' => (int) ($row->wp_post_id ?? 0),
        'title' => mb_substr((string) $row->title, 0, 100),
        'status' => (string) $row->status,
        'language' => (string) ($row->language ?? ''),
        'review_status' => (string) ($row->review_status ?? ''),
        'content_type' => $ct,
        'wp_post_type' => $wpt,
        'skip_seo_audit' => (string) ($meta['skip_seo_audit'] ?? ''),
        'has_effective_focus' => $hasFocus,
        'has_provider_focus' => isset($providerIds[$articleId]),
        'reasons' => $reasonList,
        'layer' => [
            'in_after_tab' => in_array($articleId, $afterTabIds, true),
            'in_after_skip' => in_array($articleId, $afterSkipIds, true),
            'in_after_unreviewed' => $inUnreviewed,
            'in_after_type' => $inType,
        ],
    ];
}

// Primary single reason per excluded article (first matching priority)
$primaryCounts = [
    'skip_seo_audit' => 0,
    'review_status_approved' => 0,
    'review_status_archived' => 0,
    'is_term' => 0,
    'wrong_content_type_filter' => 0,
    'other' => 0,
];
foreach ($details as $detail) {
    $r = $detail['reasons'][0] ?? 'other';
    $primaryCounts[$r] = ($primaryCounts[$r] ?? 0) + 1;
}

$uiWithFocus = 0;
$uiWithoutFocus = 0;
foreach ($uiIds as $id) {
    if (isset($effectiveIds[$id])) {
        $uiWithFocus++;
    } else {
        $uiWithoutFocus++;
    }
}

$invWithFocus = count($effectiveIds);
$invWithoutFocus = count($inventoryIds) - $invWithFocus;

$out = [
    'inventory_count' => count($inventoryIds),
    'posts_ui_count' => count($uiIds),
    'excluded_count' => count($excludedIds),
    'layer_counts' => [
        'inventory' => count($inventoryIds),
        'after_content_tab' => count($afterTabIds),
        'after_exclude_skip' => count($afterSkipIds),
        'after_unreviewed' => count($afterUnreviewedIds),
        'after_post_type' => count($afterTypeIds),
    ],
    'primary_reason_counts' => $primaryCounts,
    'reason_membership_counts' => array_map('count', $reasons),
    'focus' => [
        'inventory_with_focus' => $invWithFocus,
        'inventory_without_focus' => $invWithoutFocus,
        'ui_with_focus' => $uiWithFocus,
        'ui_without_focus' => $uiWithoutFocus,
        'excluded_with_focus' => count($excludedWithFocus),
        'excluded_without_focus' => count($excludedWithoutFocus),
        'focus_gap_inventory_minus_ui' => $invWithFocus - $uiWithFocus,
    ],
    'excluded_with_focus' => $excludedWithFocus,
    'excluded_without_focus_sample' => array_slice($excludedWithoutFocus, 0, 20),
    'excluded_details' => $details,
    'verdict' => 'Articles are imported. Gap is Posts UI exclusion (mainly review_status approved/archived and/or skip_seo_audit), not missing inventory and not keyword sync.',
];

$path = __DIR__.'/_audit_site3_posts_ui_exclusion_report.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "WROTE {$path}\n";
echo 'primary_reasons='.json_encode($primaryCounts).PHP_EOL;
echo 'focus inventory='.$invWithFocus.' ui='.$uiWithFocus.' excluded_with_focus='.count($excludedWithFocus).PHP_EOL;
echo 'excluded_without_focus='.count($excludedWithoutFocus).PHP_EOL;
