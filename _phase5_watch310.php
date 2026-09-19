<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$run = DB::connection('omi_seo_ai')->table('seo_project_runs')->where('id', 310)->first();
$ri = DB::connection('omi_seo_ai')->table('seo_project_run_items')->where('id', 825)->first();
$article = DB::connection('omi_seo_ai')->table('articles')->where('id', 8553)->first();
$body = (string) ($article->body ?? '');
$settings = is_string($run->settings ?? null) ? json_decode($run->settings, true) : ($run->settings ?? []);
$ad = $settings['php_engine']['active_dispatch'] ?? null;

echo 'run_status=' . ($run->status ?? '') . PHP_EOL;
echo 'ri_status=' . ($ri->status ?? '') . PHP_EOL;
echo 'ri_message=' . substr((string)($ri->message ?? ''), 0, 250) . PHP_EOL;
echo 'ri_error=' . substr((string)($ri->error_message ?? ''), 0, 250) . PHP_EOL;
echo 'ri_started=' . ($ri->started_at ?? '') . ' finished=' . ($ri->finished_at ?? '') . PHP_EOL;
echo 'step=' . (is_array($ad) ? ($ad['current_step'] ?? '') : '') . PHP_EOL;
echo 'body_len=' . strlen($body) . ' hash=' . hash('sha256', trim($body)) . ' updated=' . ($article->updated_at ?? '') . PHP_EOL;
echo 'jobs=' . DB::table('jobs')->where('queue', 'seo-content-run')->count() . PHP_EOL;

$prs = DB::connection('omi_seo_ai')->table('prompt_results')->where('created_at', '>=', '2026-09-19 07:21:00')->orderByDesc('id')->limit(5)->get(['id','status','canonical_prompt_key','created_at','finished_at','content_project_id','project_item_id','run_id']);
foreach ($prs as $p) echo 'PR\t' . json_encode($p, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$links = DB::connection('omi_seo_ai')->table('seo_prompt_result_links')->where('created_at', '>=', '2026-09-19 07:21:00')->orderByDesc('id')->limit(8)->get();
foreach ($links as $l) echo 'LINK\t' . json_encode($l, JSON_UNESCAPED_UNICODE) . PHP_EOL;
