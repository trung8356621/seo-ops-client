<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$runs = DB::connection('omi_seo_ai')->table('seo_project_runs')->where('project_id', 900)->orderByDesc('id')->limit(3)->get(['id','status','mode','created_at','settings']);
foreach ($runs as $r) {
    $settings = is_string($r->settings) ? json_decode($r->settings, true) : $r->settings;
    echo "RUN\t" . json_encode([
        'id' => $r->id,
        'status' => $r->status,
        'mode' => $r->mode,
        'created_at' => $r->created_at,
        'rerun_from_step' => $settings['rerun_from_step'] ?? null,
        'rerun' => $settings['rerun'] ?? null,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
$prs = DB::connection('omi_seo_ai')->table('prompt_results')->orderByDesc('id')->limit(5)->get(['id','canonical_prompt_key','status','created_at','started_at','finished_at']);
foreach ($prs as $p) {
    echo "PR\t" . json_encode($p, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
$jobs = DB::table('jobs')->orderByDesc('id')->limit(5)->get(['id','queue','payload','created_at']);
echo 'jobs_pending=' . DB::table('jobs')->count() . PHP_EOL;
foreach ($jobs as $j) {
    $payload = json_decode($j->payload, true);
    echo "JOB\t" . json_encode([
        'id' => $j->id,
        'queue' => $j->queue,
        'displayName' => $payload['displayName'] ?? null,
        'created_at' => $j->created_at,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
echo "DONE" . PHP_EOL;
