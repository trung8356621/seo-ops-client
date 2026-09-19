<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$run = DB::connection('omi_seo_ai')->table('seo_project_runs')->where('id', 311)->first();
$ri = DB::connection('omi_seo_ai')->table('seo_project_run_items')->where('id', 826)->first();
$article = DB::connection('omi_seo_ai')->table('articles')->where('id', 8553)->first();
$body = (string)($article->body ?? '');
$settings = is_string($run->settings ?? null) ? json_decode($run->settings, true) : ($run->settings ?? []);
$ad = $settings['php_engine']['active_dispatch'] ?? null;
echo 'run=' . ($run->status ?? '') . ' ri=' . ($ri->status ?? '') . ' step=' . (is_array($ad) ? ($ad['current_step'] ?? '') : '') . PHP_EOL;
echo 'msg=' . substr((string)($ri->message ?? ''), 0, 220) . PHP_EOL;
echo 'err=' . substr((string)($ri->error_message ?? ''), 0, 220) . PHP_EOL;
echo 'body_len=' . strlen($body) . ' hash=' . hash('sha256', trim($body)) . ' updated=' . ($article->updated_at ?? '') . PHP_EOL;
echo 'jobs=' . DB::table('jobs')->where('queue', 'seo-content-run')->count() . PHP_EOL;
$prs = DB::connection('omi_seo_ai')->table('prompt_results')->where('created_at', '>=', '2026-09-19 07:25:00')->orderByDesc('id')->limit(3)->get(['id','status','canonical_prompt_key','created_at','finished_at']);
foreach ($prs as $p) echo 'PR '.json_encode($p).PHP_EOL;
