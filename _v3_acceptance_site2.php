<?php

declare(strict_types=1);

/**
 * Site Sync V3 live acceptance driver — site_id=2 (exclude 6/7).
 *
 * php _v3_acceptance_site2.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncV3Receipt;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncRunExecution;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Throwable;

const TEST_SITE_ID = 2;
const REPORT_PATH = __DIR__.'/_v3_acceptance_site2_report.json';

function out(string $msg): void
{
    echo '['.date('H:i:s').'] '.$msg.PHP_EOL;
}

function seoDb()
{
    return DB::connection('omi_seo_ai');
}

/**
 * Tracked acceptance fixtures — always cleaned in finally / shutdown.
 *
 * @var array{
 *   created_post_ids: list<int>,
 *   created_term_ids: list<int>,
 *   temporary_mutations: list<array{wp_id: int, original_title: string, article_id: int|null}>
 * }
 */
$fixtures = [
    'created_post_ids' => [],
    'created_term_ids' => [],
    'temporary_mutations' => [],
];

$site = Site::query()->find(TEST_SITE_ID);
if ($site === null) {
    fwrite(STDERR, "Site missing\n");
    exit(1);
}

$domain = trim((string) $site->domain);
$base = preg_match('#^https?://#i', $domain) ? rtrim($domain, '/') : 'https://'.ltrim($domain, '/');
$readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
$writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
$client = app(WordPressSiteSyncV3Client::class);
$orch = app(RunSiteSyncV3Orchestrator::class);

$report = [
    'started_at' => now()->toIso8601String(),
    'site_id' => TEST_SITE_ID,
    'domain' => $base,
    'steps' => [],
    'fixture_cleanup' => null,
];

$cleanupFixtures = static function () use (&$fixtures, &$report, $site, $base, $writeToken): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $siteId = TEST_SITE_ID;
    $cleaned = [
        'wp_trashed' => [],
        'laravel_soft_deleted' => [],
        'titles_restored' => [],
        'errors' => [],
    ];

    foreach ($fixtures['temporary_mutations'] as $mut) {
        $wpId = (int) ($mut['wp_id'] ?? 0);
        $title = (string) ($mut['original_title'] ?? '');
        if ($wpId <= 0 || $title === '' || $writeToken === '') {
            continue;
        }
        try {
            $resp = Http::timeout(60)->acceptJson()->withToken($writeToken)
                ->post($base.'/wp-json/omi-seo-ai/v1/posts/'.$wpId.'/editor-sync', [
                    'title' => $title,
                ]);
            $link = WordpressArticleLink::query()
                ->where('site_id', $siteId)
                ->where('wp_post_id', $wpId)
                ->first();
            if ($link !== null) {
                $article = SeoArticle::query()->find((int) $link->article_id);
                if ($article !== null) {
                    $article->title = $title;
                    $article->save();
                }
            }
            $cleaned['titles_restored'][] = [
                'wp_id' => $wpId,
                'title' => $title,
                'http' => $resp->status(),
            ];
            out("CLEANUP restore title wp={$wpId}");
        } catch (Throwable $e) {
            $cleaned['errors'][] = 'restore '.$wpId.': '.$e->getMessage();
        }
    }

    foreach ($fixtures['created_post_ids'] as $wpId) {
        $wpId = (int) $wpId;
        if ($wpId <= 0) {
            continue;
        }
        try {
            if ($writeToken !== '') {
                $resp = Http::timeout(60)->acceptJson()->withToken($writeToken)
                    ->post($base.'/wp-json/omi-seo-ai/v1/posts/'.$wpId.'/editor-sync', [
                        'status' => 'trash',
                    ]);
                $cleaned['wp_trashed'][] = ['wp_id' => $wpId, 'http' => $resp->status()];
                out("CLEANUP trash wp={$wpId} HTTP {$resp->status()}");
            }
            $link = WordpressArticleLink::query()
                ->where('site_id', $siteId)
                ->where('wp_post_id', $wpId)
                ->first();
            if ($link !== null) {
                $article = SeoArticle::withTrashed()->find((int) $link->article_id);
                if ($article !== null && ! $article->trashed()) {
                    $article->delete();
                    $cleaned['laravel_soft_deleted'][] = (int) $article->id;
                }
            }
        } catch (Throwable $e) {
            $cleaned['errors'][] = 'trash '.$wpId.': '.$e->getMessage();
        }
    }

    $report['fixture_cleanup'] = $cleaned;
    // Refresh report file if already written, else store for final write.
    if (is_file(REPORT_PATH)) {
        $existing = json_decode((string) file_get_contents(REPORT_PATH), true);
        if (is_array($existing)) {
            $existing['fixture_cleanup'] = $cleaned;
            file_put_contents(
                REPORT_PATH,
                json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }
    }
};

register_shutdown_function($cleanupFixtures);

out("SITE {$base}");

// --- Precondition V3 ---
$discover0 = $client->discover($site);
if (! ($discover0['success'] ?? false)) {
    $report['verdict'] = 'FAIL';
    $report['blocker'] = 'V3 discover failed: '.($discover0['message'] ?? '');
    file_put_contents(REPORT_PATH, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    out('STOP: '.$report['blocker']);
    exit(2);
}
$d0 = $discover0['discover'];
$bounds0 = is_array($d0['snapshot_bounds'] ?? null) ? $d0['snapshot_bounds'] : [];
$bridge = (string) ($d0['profile']['bridge_version'] ?? '');
out("bridge={$bridge} total={$d0['total']} content_max={$bounds0['content_max_id']} term_max={$bounds0['term_max_id']}");
$report['pre_discover'] = [
    'bridge' => $bridge,
    'total' => $d0['total'],
    'by_content_type' => $d0['by_content_type'] ?? null,
    'snapshot_bounds' => $bounds0,
    'snapshot_at' => $d0['snapshot_at'] ?? null,
];
if ((int) ($d0['schema_version'] ?? 0) !== 3 || empty($bounds0['content_max_id'])) {
    $report['verdict'] = 'FAIL';
    $report['blocker'] = 'discover missing schema_version/bounds';
    file_put_contents(REPORT_PATH, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    exit(2);
}

// --- Laravel baseline ---
$siteId = TEST_SITE_ID;
$wpBacked = seoDb()->table('wordpress_article_links as wal')
    ->join('articles as a', 'a.id', '=', 'wal.article_id')
    ->where('wal.site_id', $siteId)
    ->where('wal.wp_post_id', '>', 0)
    ->whereNull('a.deleted_at')
    ->count();

$typeCounts = ['post' => 0, 'page' => 0, 'product' => 0, 'null' => 0];
$rows = seoDb()->table('wordpress_article_links as wal')
    ->join('articles as a', 'a.id', '=', 'wal.article_id')
    ->leftJoin('article_meta as am', function ($j) {
        $j->on('am.article_id', '=', 'a.id')->where('am.meta_key', '=', 'content_type');
    })
    ->where('wal.site_id', $siteId)
    ->where('wal.wp_post_id', '>', 0)
    ->whereNull('a.deleted_at')
    ->select(['wal.wp_post_id', 'am.meta_value as content_type', 'a.body', 'a.id as article_id'])
    ->get();

$bodyNonNull = 0;
$contentTypeNull = 0;
foreach ($rows as $r) {
    $ct = $r->content_type;
    if ($ct === null || $ct === '') {
        $contentTypeNull++;
        $typeCounts['null']++;
    } elseif (isset($typeCounts[$ct])) {
        $typeCounts[$ct]++;
    }
    if ($r->body !== null && trim((string) $r->body) !== '') {
        $bodyNonNull++;
    }
}

$wpPostContent = 0;
$wpPostContentSource = 0;
if (Schema::connection('omi_seo_ai')->hasTable('article_meta')) {
    $ids = $rows->pluck('article_id')->all();
    if ($ids !== []) {
        $wpPostContent = seoDb()->table('article_meta')
            ->whereIn('article_id', $ids)
            ->where('meta_key', 'wp_post_content')
            ->count();
        $wpPostContentSource = seoDb()->table('article_meta')
            ->whereIn('article_id', $ids)
            ->where('meta_key', 'wp_post_content_source')
            ->count();
    }
}

$baselineBefore = [
    'laravel_wp_backed' => $wpBacked,
    'by_content_type' => $typeCounts,
    'content_type_null' => $contentTypeNull,
    'body_non_null_wp_backed' => $bodyNonNull,
    'wp_post_content' => $wpPostContent,
    'wp_post_content_source' => $wpPostContentSource,
    'v3_baseline_at' => (string) ($site->getMeta(SiteSyncV3Schema::META_BASELINE_COMPLETED_AT) ?? ''),
];
$report['laravel_before'] = $baselineBefore;
out('Laravel WP-backed='.$wpBacked.' body_non_null='.$bodyNonNull.' wp_post_content='.$wpPostContent);

// --- Pick trace IDs from existing WP-backed posts ---
$candidates = seoDb()->table('wordpress_article_links as wal')
    ->join('articles as a', 'a.id', '=', 'wal.article_id')
    ->where('wal.site_id', $siteId)
    ->where('wal.wp_post_id', '>', 0)
    ->whereNull('a.deleted_at')
    ->orderBy('wal.wp_post_id')
    ->limit(20)
    ->get(['wal.wp_post_id', 'a.id as article_id', 'a.title']);

$newest = seoDb()->table('wordpress_article_links')
    ->where('site_id', $siteId)
    ->where('wp_post_id', '>', 0)
    ->orderByDesc('wp_post_id')
    ->value('wp_post_id');

$traceNormal = null;
$traceUpdate = null;
foreach ($candidates as $c) {
    $wpId = (int) $c->wp_post_id;
    if ($wpId <= 0) {
        continue;
    }
    // Prefer low IDs for update (pass early in FULL).
    if ($traceUpdate === null && $wpId < (int) $bounds0['content_max_id'] / 2) {
        $traceUpdate = $wpId;
    }
}
if ($traceUpdate === null && $candidates->isNotEmpty()) {
    $traceUpdate = (int) $candidates->first()->wp_post_id;
}

$updateOriginalTitle = '';
$updateArticleId = null;
if ($traceUpdate) {
    $updRow = $candidates->first(static fn ($c): bool => (int) $c->wp_post_id === (int) $traceUpdate);
    $updateOriginalTitle = trim((string) ($updRow->title ?? ''));
    $updateArticleId = $updRow !== null ? (int) $updRow->article_id : null;
    if ($updateOriginalTitle === '') {
        $linkTitle = seoDb()->table('articles as a')
            ->join('wordpress_article_links as wal', 'wal.article_id', '=', 'a.id')
            ->where('wal.site_id', $siteId)
            ->where('wal.wp_post_id', $traceUpdate)
            ->value('a.title');
        $updateOriginalTitle = trim((string) ($linkTitle ?? ''));
    }
    if ($updateOriginalTitle !== '') {
        $fixtures['temporary_mutations'][] = [
            'wp_id' => (int) $traceUpdate,
            'original_title' => $updateOriginalTitle,
            'article_id' => $updateArticleId,
        ];
    }
}

// Fetch a post with analysis via V3 records for TRACE_NORMAL near middle
$mid = (int) max(0, ((int) $bounds0['content_max_id']) - 500);
$probe = $client->records($site, [
    'schema' => SiteSyncV3Schema::VERSION,
    'resource' => 'content',
    'mode' => 'full',
    'limit' => 5,
    'cursor' => ['after_id' => $mid],
    'snapshot_at' => (string) ($d0['snapshot_at'] ?? ''),
    'snapshot_bounds' => $bounds0,
]);
$probeItems = is_array($probe['records']['items'] ?? null) ? $probe['records']['items'] : [];
foreach ($probeItems as $item) {
    if (! is_array($item) || ($item['op'] ?? '') === 'delete') {
        continue;
    }
    $fk = $item['seo']['focus_keywords'] ?? [];
    $links = $item['links'] ?? [];
    if (is_array($fk) && $fk !== [] && is_array($links)) {
        $traceNormal = (int) ($item['wp_id'] ?? 0);
        $report['trace_normal_wp_payload'] = [
            'wp_id' => $traceNormal,
            'title' => $item['title'] ?? null,
            'focus_keywords' => $fk,
            'link_count' => count($links),
            'provider_score' => $item['seo']['provider_score'] ?? null,
            'content_type' => $item['content_type'] ?? null,
            'wp_post_type' => $item['wp_post_type'] ?? null,
        ];
        break;
    }
}
if ($traceNormal === null && $probeItems !== []) {
    $traceNormal = (int) ($probeItems[0]['wp_id'] ?? 0);
}

// Create disposable DELETE candidate BEFORE discover freeze of the run
$deleteTitle = 'V3-ACCEPT-DELETE-'.date('Ymd-His');
$createDel = Http::timeout(60)->acceptJson()->withToken($writeToken)
    ->post($base.'/wp-json/omi-seo-ai/v1/posts', [
        'title' => $deleteTitle,
        'post_type' => 'post',
        'status' => 'draft',
        'post_content' => '<p>V3 acceptance disposable delete candidate.</p>',
    ]);
$delJson = $createDel->json();
$traceDelete = (int) ($delJson['wp_post_id'] ?? $delJson['post']['wp_id'] ?? $delJson['id'] ?? 0);
out("CREATE delete-candidate HTTP {$createDel->status()} id={$traceDelete}");
$report['trace_delete_create'] = ['http' => $createDel->status(), 'body' => $delJson, 'wp_id' => $traceDelete];
if ($traceDelete > 0) {
    $fixtures['created_post_ids'][] = $traceDelete;
}
if ($traceDelete <= 0) {
    out('WARN: could not create delete candidate — delete race may fail');
}

$report['traces'] = [
    'TRACE_NORMAL_ID' => $traceNormal,
    'TRACE_NEWEST_ID' => (int) $newest,
    'TRACE_UPDATE_ID' => $traceUpdate,
    'TRACE_DELETE_ID' => $traceDelete,
    'TRACE_CREATE_ID' => null,
];
out('TRACES normal='.$traceNormal.' newest='.$newest.' update='.$traceUpdate.' delete='.$traceDelete);

// Cancel any active run
$active = SeoSiteSyncRun::query()
    ->where('site_id', $siteId)
    ->whereIn('status', ['pending', 'running'])
    ->get();
foreach ($active as $r) {
    $orch->cancel((int) $r->id);
    out('canceled active run '.$r->id);
}

// --- Start force-full V3 ---
$t0 = microtime(true);
$start = $orch->start($site, [
    'force_full' => true,
    'mode' => SiteSyncV3Schema::MODE_FORCE_FULL,
    'supersede_active' => true,
    'trigger_source' => 'v3_acceptance',
    'sync' => false,
]);
$runId = (int) ($start['run_id'] ?? 0);
out('START '.json_encode($start));
$report['start'] = $start;
if ($runId <= 0 || (int) ($start['protocol'] ?? 0) !== 3) {
    $report['verdict'] = 'FAIL';
    $report['blocker'] = 'V3 run not started / wrong protocol';
    file_put_contents(REPORT_PATH, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    exit(3);
}

$run = SeoSiteSyncRun::query()->find($runId);
$gen = app(SiteSyncRunExecution::class)->readGeneration($run);
$meta = is_array($run->meta) ? $run->meta : [];

// Drive ticks manually (same as queue job) so we can inject races
$importStarted = false;
$createDone = false;
$updateDone = false;
$deleteDone = false;
$createId = 0;
$maxTicks = 500;
$ticks = 0;
$races = [
    'create' => ['expected' => 'catch_up', 'actual' => null, 'result' => 'PENDING'],
    'update' => ['expected' => 'catch_up', 'actual' => null, 'result' => 'PENDING'],
    'delete' => ['expected' => 'tombstone', 'actual' => null, 'result' => 'PENDING'],
];

while ($ticks < $maxTicks) {
    $ticks++;
    $orch->handle($runId);
    $run = SeoSiteSyncRun::query()->find($runId);
    $status = (string) $run->status;
    $phase = (string) $run->current_step;
    $meta = is_array($run->meta) ? $run->meta : [];
    $counters = is_array($run->counters) ? $run->counters : [];
    $cursor = is_array($meta['cursor'] ?? null) ? $meta['cursor'] : [];
    $jobNo = (int) ($meta['job_number'] ?? 0);
    $fetched = (int) ($counters['fetched'] ?? 0);

    if ($ticks % 5 === 0 || in_array($phase, ['catch_up', 'verify', 'complete', 'needs_attention'], true)) {
        out("tick={$ticks} status={$status} phase={$phase} job={$jobNo} fetched={$fetched} cursor=".json_encode($cursor));
    }

    if ($phase === SiteSyncV3Schema::PHASE_IMPORT && $fetched >= 10) {
        $importStarted = true;
    }

    // LIVE CREATE after import progressing
    if ($importStarted && ! $createDone && $phase === SiteSyncV3Schema::PHASE_IMPORT) {
        $snapMax = (int) ($meta['snapshot_content_max_id'] ?? $bounds0['content_max_id'] ?? 0);
        $title = 'V3-ACCEPT-CREATE-'.date('Ymd-His');
        $resp = Http::timeout(60)->acceptJson()->withToken($writeToken)
            ->post($base.'/wp-json/omi-seo-ai/v1/posts', [
                'title' => $title,
                'post_type' => 'post',
                'status' => 'publish',
                'post_content' => '<p>V3 acceptance create during FULL. <a href="/">home</a></p>',
                'seo' => [
                    'focus_keyword' => 'v3 accept create keyword',
                ],
            ]);
        $cj = $resp->json();
        $createId = (int) ($cj['wp_post_id'] ?? $cj['post']['wp_id'] ?? $cj['id'] ?? 0);
        $createDone = true;
        $report['traces']['TRACE_CREATE_ID'] = $createId;
        $report['create_http'] = ['status' => $resp->status(), 'body' => $cj, 'snapshot_max' => $snapMax];
        if ($createId > 0) {
            $fixtures['created_post_ids'][] = $createId;
        }
        out("CREATE during import id={$createId} snapshot_max={$snapMax} >max=".($createId > $snapMax ? 'yes' : 'NO'));
        if ($createId > $snapMax) {
            $races['create']['result'] = 'CREATED';
        } else {
            $races['create']['result'] = 'FAIL_ID_NOT_ABOVE_BOUND';
        }
    }

    // UPDATE after cursor passed update id
    $afterId = (int) ($cursor['after_id'] ?? 0);
    if ($importStarted && ! $updateDone && $traceUpdate && $afterId >= $traceUpdate && $phase === SiteSyncV3Schema::PHASE_IMPORT) {
        $newTitle = 'V3-CATCHUP-TEST '.date('His');
        $resp = Http::timeout(60)->acceptJson()->withToken($writeToken)
            ->post($base.'/wp-json/omi-seo-ai/v1/posts/'.$traceUpdate.'/editor-sync', [
                'title' => $newTitle.' [V3-CATCHUP-TEST]',
            ]);
        $updateDone = true;
        $report['update_http'] = ['status' => $resp->status(), 'body' => $resp->json(), 'wp_id' => $traceUpdate, 'title' => $newTitle.' [V3-CATCHUP-TEST]'];
        out("UPDATE {$traceUpdate} HTTP {$resp->status()}");
        $races['update']['result'] = $resp->successful() ? 'QUEUED' : 'FAIL_HTTP';
        $races['update']['actual'] = $resp->json();
    }

    // DELETE after FULL has seen the candidate (link last_seen set), once import leaves that ID.
    if ($importStarted && ! $deleteDone && $traceDelete > 0 && $phase === SiteSyncV3Schema::PHASE_IMPORT) {
        $seen = WordpressArticleLink::query()
            ->where('site_id', $siteId)
            ->where('wp_post_id', $traceDelete)
            ->first();
        $genNow = (int) ($meta['sync_generation'] ?? $runId);
        $seenGen = (int) ($seen?->last_seen_sync_generation ?? 0);
        if ($seen !== null && ($seenGen === $genNow || $seenGen > 0 || $afterId >= $traceDelete)) {
            $resp = Http::timeout(60)->acceptJson()->withToken($writeToken)
                ->post($base.'/wp-json/omi-seo-ai/v1/posts/'.$traceDelete.'/editor-sync', [
                    'status' => 'trash',
                ]);
            $deleteDone = true;
            $body = $resp->json();
            $trashed = is_array($body) && (string) ($body['status'] ?? '') === 'trash';
            $report['delete_http'] = ['status' => $resp->status(), 'body' => $body, 'wp_id' => $traceDelete, 'seen_gen' => $seenGen, 'trashed' => $trashed, 'when' => 'import'];
            out('TRASH '.$traceDelete.' HTTP '.$resp->status().' status='.(string) ($body['status'] ?? '').' seen_gen='.$seenGen);
            if ($trashed) {
                $races['delete']['result'] = 'QUEUED';
            } else {
                $races['delete']['result'] = 'FAIL_NEED_BRIDGE_GE_1.0.86';
                $report['blocker_delete'] = 'Bridge '.$bridge.' ignores status=trash (got '.(string) ($body['status'] ?? '').'). Install wp-seo-ai-1.0.86.zip from GitHub release 1.0.86.';
            }
            $races['delete']['actual'] = $body;
        }
    }

    // Fallback: if import finished without firing delete, trash at catch-up entry.
    if (! $deleteDone && $traceDelete > 0
        && in_array($phase, [SiteSyncV3Schema::PHASE_RECONCILE_STALE, SiteSyncV3Schema::PHASE_CATCH_UP], true)
    ) {
        $seen = WordpressArticleLink::query()
            ->where('site_id', $siteId)
            ->where('wp_post_id', $traceDelete)
            ->first();
        if ($seen !== null) {
            $resp = Http::timeout(60)->acceptJson()->withToken($writeToken)
                ->post($base.'/wp-json/omi-seo-ai/v1/posts/'.$traceDelete.'/editor-sync', [
                    'status' => 'trash',
                ]);
            $deleteDone = true;
            $body = $resp->json();
            $trashed = is_array($body) && (string) ($body['status'] ?? '') === 'trash';
            $report['delete_http'] = ['status' => $resp->status(), 'body' => $body, 'wp_id' => $traceDelete, 'trashed' => $trashed, 'when' => 'catch_up_entry'];
            out('TRASH-FALLBACK '.$traceDelete.' HTTP '.$resp->status().' status='.(string) ($body['status'] ?? ''));
            if ($trashed) {
                $races['delete']['result'] = 'QUEUED';
            } else {
                $races['delete']['result'] = 'FAIL_NEED_BRIDGE_GE_1.0.86';
                $report['blocker_delete'] = 'Bridge '.$bridge.' ignores status=trash (got '.(string) ($body['status'] ?? '').'). Install wp-seo-ai-1.0.86.zip from GitHub release 1.0.86.';
            }
            $races['delete']['actual'] = $body;
        }
    }

    if (in_array($status, ['completed', 'needs_attention', 'failed', 'canceled'], true)
        || $phase === SiteSyncV3Schema::PHASE_NEEDS_ATTENTION
    ) {
        break;
    }

    // phase=complete still needs one tick to persist status=completed + baseline.
    if ($phase === SiteSyncV3Schema::PHASE_COMPLETE && $status === 'running') {
        continue;
    }

    // If import finished without update (cursor jumped), force update before catch-up ends
    if (! $updateDone && $phase === SiteSyncV3Schema::PHASE_CATCH_UP && $traceUpdate) {
        $resp = Http::timeout(60)->acceptJson()->withToken($writeToken)
            ->post($base.'/wp-json/omi-seo-ai/v1/posts/'.$traceUpdate.'/editor-sync', [
                'title' => 'Late [V3-CATCHUP-TEST] '.date('His'),
            ]);
        $updateDone = true;
        $report['update_http'] = ['status' => $resp->status(), 'body' => $resp->json(), 'late' => true];
        out("LATE UPDATE {$traceUpdate} HTTP {$resp->status()}");
        $races['update']['result'] = $resp->successful() ? 'QUEUED_LATE' : 'FAIL_HTTP';
    }
}

$duration = microtime(true) - $t0;
$run = SeoSiteSyncRun::query()->find($runId);
$meta = is_array($run->meta) ? $run->meta : [];
$counters = is_array($run->counters) ? $run->counters : [];
$verify = is_array($meta['verify'] ?? null) ? $meta['verify'] : [];

out('DONE status='.$run->status.' phase='.$run->current_step.' duration_s='.round($duration, 1));

// Prove create not in FULL receipts
$fullHadCreate = false;
if ($createId > 0) {
    $receipts = SeoSiteSyncV3Receipt::query()->where('run_id', $runId)->where('resource', 'content')->get();
    // receipts don't store item IDs — check article last_seen during import vs catch-up resource
    $link = WordpressArticleLink::query()->where('site_id', $siteId)->where('wp_post_id', $createId)->first();
    $article = $link ? SeoArticle::query()->find($link->article_id) : null;
    $races['create']['actual'] = [
        'laravel_exists' => $article !== null,
        'article_id' => $article?->id,
        'title' => $article?->title,
    ];
    if ($article !== null && str_contains((string) $article->title, 'V3-ACCEPT-CREATE')) {
        $races['create']['result'] = 'PASS_CATCHUP_OR_PRESENT';
    } elseif (($races['create']['result'] ?? '') === 'QUEUED') {
        $races['create']['result'] = 'FAIL_MISSING_AFTER_RUN';
    }
    // FULL exclusion: createId > snapshot max
    $snapMax = (int) ($meta['snapshot_content_max_id'] ?? 0);
    $report['create_above_bound'] = $createId > $snapMax;
}

// Update check
if ($traceUpdate) {
    $link = WordpressArticleLink::query()->where('site_id', $siteId)->where('wp_post_id', $traceUpdate)->first();
    $article = $link ? SeoArticle::query()->find($link->article_id) : null;
    $title = (string) ($article?->title ?? '');
    $races['update']['actual'] = ['title' => $title];
    if (str_contains($title, 'V3-CATCHUP-TEST')) {
        $races['update']['result'] = 'PASS';
    } elseif (str_starts_with((string) ($races['update']['result'] ?? ''), 'QUEUED')) {
        $races['update']['result'] = 'FAIL_TITLE_NOT_UPDATED';
    }
}

// Delete check
if ($traceDelete > 0) {
    $link = WordpressArticleLink::query()->where('site_id', $siteId)->where('wp_post_id', $traceDelete)->first();
    $article = $link ? SeoArticle::withTrashed()->find($link->article_id) : null;
    $gone = $article === null || method_exists($article, 'trashed') && $article->trashed();
    $races['delete']['actual'] = [
        'laravel_gone_or_trashed' => $gone,
        'article_id' => $article?->id,
        'deleted_at' => $article?->deleted_at,
    ];
    if ($gone && ($races['delete']['result'] ?? '') === 'QUEUED') {
        $races['delete']['result'] = 'PASS';
    } elseif (($races['delete']['result'] ?? '') === 'QUEUED') {
        $races['delete']['result'] = 'FAIL_STILL_PRESENT';
    }
}

// Final Laravel counts
$wpBackedAfter = seoDb()->table('wordpress_article_links as wal')
    ->join('articles as a', 'a.id', '=', 'wal.article_id')
    ->where('wal.site_id', $siteId)
    ->where('wal.wp_post_id', '>', 0)
    ->whereNull('a.deleted_at')
    ->count();

$bodyNonNullAfter = seoDb()->table('wordpress_article_links as wal')
    ->join('articles as a', 'a.id', '=', 'wal.article_id')
    ->where('wal.site_id', $siteId)
    ->where('wal.wp_post_id', '>', 0)
    ->whereNull('a.deleted_at')
    ->whereNotNull('a.body')
    ->where('a.body', '!=', '')
    ->count();

$receiptCount = SeoSiteSyncV3Receipt::query()->where('run_id', $runId)->count();
$receiptStats = SeoSiteSyncV3Receipt::query()->where('run_id', $runId)
    ->selectRaw('count(*) as n, avg(total_ms) as avg_ms, avg(item_count) as avg_items, sum(item_count) as sum_items')
    ->first();

$site->refresh();
$baselineAfter = (string) ($site->getMeta(SiteSyncV3Schema::META_BASELINE_COMPLETED_AT) ?? '');

$report['run'] = [
    'run_id' => $runId,
    'status' => $run->status,
    'phase' => $run->current_step,
    'protocol_version' => $run->protocol_version,
    'snapshot_at' => $meta['snapshot_at'] ?? null,
    'snapshot_content_max_id' => $meta['snapshot_content_max_id'] ?? null,
    'snapshot_term_max_id' => $meta['snapshot_term_max_id'] ?? null,
    'initial_expected_total' => $meta['initial_expected_total'] ?? null,
    'final_expected_total' => $meta['final_expected_total'] ?? null,
    'final_manifest_at' => $meta['final_manifest_at'] ?? null,
    'verify' => $verify,
    'counters' => $counters,
    'job_number' => $meta['job_number'] ?? null,
    'retry_count' => $meta['retry_count'] ?? null,
    'catch_up_round' => $meta['catch_up_round'] ?? null,
    'error_code' => $meta['error_code'] ?? null,
    'error_message' => $run->error_message,
    'ticks' => $ticks,
    'duration_sec' => round($duration, 2),
];
$report['races'] = $races;
$report['laravel_after'] = [
    'wp_backed' => $wpBackedAfter,
    'body_non_null' => $bodyNonNullAfter,
    'baseline_at' => $baselineAfter,
];
$report['receipts'] = [
    'count' => $receiptCount,
    'avg_ms' => $receiptStats->avg_ms ?? null,
    'avg_items' => $receiptStats->avg_items ?? null,
    'sum_items' => $receiptStats->sum_items ?? null,
];
$fetched = max(1, (int) ($counters['fetched'] ?? 0));
$report['performance'] = [
    'duration_sec' => round($duration, 2),
    'records_fetched' => (int) ($counters['fetched'] ?? 0),
    'records_per_min' => round($fetched / max(0.01, $duration / 60), 1),
    'jobs' => (int) ($meta['job_number'] ?? 0),
    'ticks' => $ticks,
];

$missingIds = is_array($verify['sample_missing_wp_ids'] ?? null) ? $verify['sample_missing_wp_ids'] : null;
$extraIds = is_array($verify['sample_extra_wp_ids'] ?? null) ? $verify['sample_extra_wp_ids'] : null;
$typeMismatch = is_array($verify['type_mismatch'] ?? null) ? $verify['type_mismatch'] : null;
$passVerify = $run->status === 'completed'
    && is_array($missingIds) && $missingIds === []
    && is_array($extraIds) && $extraIds === []
    && is_array($typeMismatch) && $typeMismatch === [];

$createPass = str_starts_with((string) ($races['create']['result'] ?? ''), 'PASS');
$updatePass = str_starts_with((string) ($races['update']['result'] ?? ''), 'PASS');
$deleteResult = (string) ($races['delete']['result'] ?? '');
$deletePass = str_starts_with($deleteResult, 'PASS');
$deleteBridgeBlocker = str_contains($deleteResult, 'NEED_BRIDGE');

$baselineOk = trim((string) ($site->fresh()->getMeta(SiteSyncV3Schema::META_BASELINE_COMPLETED_AT) ?? '')) !== '';
$urlKw = (int) seoDb()->table('keywords')->where('phrase', 'like', '%maybalotuixachgiare%')->count();

if ($passVerify && $createPass && $updatePass && $deletePass && $baselineOk && $urlKw === 0) {
    $report['verdict'] = 'PASS — SITE 2 GREEN';
} elseif ($passVerify && $createPass && $updatePass && $deleteBridgeBlocker && $baselineOk && $urlKw === 0) {
    $report['verdict'] = 'BLOCKED — need bridge >= 1.0.86 for trash/delete race';
} elseif (! $createPass || ! $updatePass || ($deleteResult !== '' && ! $deletePass && ! $deleteBridgeBlocker)) {
    $report['verdict'] = 'FAIL — race invariant';
} elseif ($run->status === 'completed') {
    $report['verdict'] = 'PASS WITH FOLLOW-UP';
} else {
    $report['verdict'] = 'FAIL — architecture/runtime bug';
}
$report['baseline_ok'] = $baselineOk;
$report['url_keyword_count'] = $urlKw;
$report['delete_bridge_blocker'] = $deleteBridgeBlocker;

$report['finished_at'] = now()->toIso8601String();
$report['fixtures_tracked'] = [
    'created_post_ids' => $fixtures['created_post_ids'],
    'created_term_ids' => $fixtures['created_term_ids'],
    'temporary_mutations' => $fixtures['temporary_mutations'],
];
file_put_contents(REPORT_PATH, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
out('REPORT '.REPORT_PATH);
out('VERDICT '.$report['verdict']);
echo json_encode([
    'verdict' => $report['verdict'],
    'run_id' => $runId,
    'status' => $run->status,
    'races' => $races,
    'verify' => $verify,
    'performance' => $report['performance'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;

// Explicit cleanup (also registered via shutdown for early exit / exception).
$cleanupFixtures();
