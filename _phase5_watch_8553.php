<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$runId = 309;
$riId = 824;
$articleId = 8553;

$run = DB::connection('omi_seo_ai')->table('seo_project_runs')->where('id', $runId)->first();
$ri = DB::connection('omi_seo_ai')->table('seo_project_run_items')->where('id', $riId)->first();
$task = DB::connection('omi_seo_ai')->table('seo_project_tasks')->where('id', 8799)->first();
$article = DB::connection('omi_seo_ai')->table('articles')->where('id', $articleId)->first();
$body = (string) ($article->body ?? '');

echo 'run_status=' . ($run->status ?? '') . PHP_EOL;
echo 'ri_status=' . ($ri->status ?? '') . PHP_EOL;
echo 'ri_message=' . substr((string) ($ri->message ?? ''), 0, 300) . PHP_EOL;
echo 'ri_error_code=' . ($ri->error_code ?? 'null') . PHP_EOL;
echo 'ri_error_message=' . substr((string) ($ri->error_message ?? ''), 0, 300) . PHP_EOL;
echo 'ri_started=' . ($ri->started_at ?? '') . ' finished=' . ($ri->finished_at ?? '') . PHP_EOL;
echo 'task_status=' . ($task->status ?? '') . PHP_EOL;
echo 'body_len=' . strlen($body) . PHP_EOL;
echo 'body_hash=' . hash('sha256', trim($body)) . PHP_EOL;
echo 'article_updated_at=' . ($article->updated_at ?? '') . PHP_EOL;
echo 'jobs_pending_seo=' . DB::table('jobs')->where('queue', 'seo-content-run')->count() . PHP_EOL;
echo 'failed_jobs=' . DB::table('failed_jobs')->where('payload', 'like', '%RunContentProjectArticleJob%')->orderByDesc('id')->limit(1)->count() . PHP_EOL;

$settings = is_string($run->settings ?? null) ? json_decode($run->settings, true) : ($run->settings ?? []);
$ad = $settings['php_engine']['active_dispatch'] ?? null;
if (is_array($ad)) {
    echo 'active_dispatch=' . json_encode($ad, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$prs = DB::connection('omi_seo_ai')->table('prompt_results')
    ->where('canonical_prompt_key', 'article.content.generate')
    ->where('created_at', '>=', '2026-09-19 07:15:00')
    ->orderByDesc('id')->limit(5)->get(['id','status','created_at','finished_at','content_project_id','project_item_id','run_id']);
foreach ($prs as $p) {
    echo 'PR\t' . json_encode($p, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$links = DB::connection('omi_seo_ai')->table('seo_prompt_result_links')
    ->where('project_run_id', $runId)
    ->orWhere(function ($q) use ($riId) {
        // also by recent article links
    })
    ->orderByDesc('id')->limit(10)->get();
// Better: article + recent
$links = DB::connection('omi_seo_ai')->table('seo_prompt_result_links')
    ->where('article_id', $articleId)
    ->where('created_at', '>=', '2026-09-19 07:15:00')
    ->orderByDesc('id')->limit(10)->get();
foreach ($links as $l) {
    echo 'LINK\t' . json_encode($l, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo 'DONE_WATCH'.PHP_EOL;
