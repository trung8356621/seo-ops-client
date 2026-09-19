<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function trunc($v, $n = 200) {
    if (!is_string($v)) return $v;
    return strlen($v) > $n ? substr($v, 0, $n).'...['.strlen($v).']' : $v;
}

echo "=== RUN 309 FULL ===" . PHP_EOL;
$run = DB::connection('omi_seo_ai')->table('seo_project_runs')->where('id', 309)->first();
$settings = is_string($run->settings) ? json_decode($run->settings, true) : $run->settings;
echo json_encode([
    'id' => $run->id,
    'status' => $run->status,
    'succeeded' => $run->succeeded,
    'failed' => $run->failed,
    'total' => $run->total,
    'created_at' => $run->created_at,
    'updated_at' => $run->updated_at,
    'rerun_from_step' => $settings['rerun_from_step'] ?? null,
    'rerun_include_downstream' => $settings['rerun_include_downstream'] ?? null,
    'active_dispatch' => $settings['php_engine']['active_dispatch'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

echo "=== RI 824 FULL ===" . PHP_EOL;
$ri = DB::connection('omi_seo_ai')->table('seo_project_run_items')->where('id', 824)->first();
$a = (array) $ri;
foreach (['input_snapshot','output_snapshot','message','error_message'] as $k) {
    if (isset($a[$k]) && is_string($a[$k])) {
        $a[$k.'_len'] = strlen($a[$k]);
        $a[$k] = trunc($a[$k], 400);
    }
}
echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if (!empty($ri->output_snapshot)) {
    $out = json_decode((string)$ri->output_snapshot, true);
    echo "OUTPUT_KEYS=" . json_encode(is_array($out) ? array_keys($out) : null) . PHP_EOL;
    if (is_array($out)) {
        foreach (['persist_status','ancillary_status','result_id','prompt_result_id','generated_hash','body_hash','content_hash','skip_reason','nodes','steps','failed_step','hook_key','article_id'] as $k) {
            if (array_key_exists($k, $out)) {
                echo "OUT.$k=" . json_encode($out[$k], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            }
        }
        // dig nested
        echo "OUT_PREVIEW=" . trunc(json_encode($out, JSON_UNESCAPED_UNICODE), 1500) . PHP_EOL;
    }
}

echo "=== TASK EVENTS RUN 309 ===" . PHP_EOL;
$ev = DB::connection('omi_seo_ai')->table('seo_project_task_events')->where('run_id', 309)->orderBy('id')->get();
foreach ($ev as $e) {
    echo "EV\t" . json_encode($e, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== ARTICLE NOW ===" . PHP_EOL;
$article = DB::connection('omi_seo_ai')->table('articles')->where('id', 8553)->first();
$body = (string)($article->body ?? '');
echo json_encode([
    'id' => $article->id,
    'body_len' => strlen($body),
    'body_hash' => hash('sha256', trim($body)),
    'updated_at' => $article->updated_at,
    'created_at' => $article->created_at ?? null,
    'status' => $article->status ?? null,
    'wp_post_id' => $article->wp_post_id ?? null,
    'title' => $article->title ?? null,
    'body_prefix' => trunc(strip_tags($body), 180),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

echo "=== PR / LINKS SINCE 07:15 ===" . PHP_EOL;
$prs = DB::connection('omi_seo_ai')->table('prompt_results')->where('created_at', '>=', '2026-09-19 07:15:00')->orderByDesc('id')->limit(10)->get();
foreach ($prs as $p) {
    $row = (array)$p;
    foreach (['input_snapshot','output_text'] as $h) {
        if (isset($row[$h]) && is_string($row[$h])) {
            $row[$h.'_len'] = strlen($row[$h]);
            $row[$h.'_hash'] = hash('sha256', trim($row[$h]));
            $row[$h] = trunc($row[$h], 100);
        }
    }
    echo "PR\t" . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
$links = DB::connection('omi_seo_ai')->table('seo_prompt_result_links')->where('created_at', '>=', '2026-09-19 07:15:00')->orderByDesc('id')->limit(20)->get();
foreach ($links as $l) {
    echo "LINK\t" . json_encode($l, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== ROUTING ATTEMPTS RECENT ===" . PHP_EOL;
if (Schema::connection('omi_seo_ai')->hasTable('prompt_result_routing_attempts')) {
    $atts = DB::connection('omi_seo_ai')->table('prompt_result_routing_attempts')->where('created_at', '>=', '2026-09-19 07:15:00')->orderByDesc('id')->limit(20)->get();
    echo 'attempts=' . $atts->count() . PHP_EOL;
    foreach ($atts as $at) {
        $row = (array)$at;
        foreach ($row as $k=>$v) if (is_string($v) && strlen($v)>180) $row[$k]=trunc($v,120);
        echo "ATTEMPT\t" . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}

echo "=== FAILED JOBS RECENT ===" . PHP_EOL;
$fj = DB::table('failed_jobs')->orderByDesc('id')->limit(3)->get();
foreach ($fj as $f) {
    $payload = json_decode($f->payload, true);
    echo "FAILED\t" . json_encode([
        'id' => $f->id,
        'uuid' => $f->uuid ?? null,
        'queue' => $f->queue,
        'displayName' => $payload['displayName'] ?? null,
        'failed_at' => $f->failed_at,
        'exception' => trunc((string)$f->exception, 500),
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== WORKER LOG TAIL ===" . PHP_EOL;
$log = __DIR__ . '/storage/logs/queue-seo-content-run-8553.log';
$err = __DIR__ . '/storage/logs/queue-seo-content-run-8553.log.err';
foreach ([$log, $err] as $path) {
    if (is_file($path)) {
        echo "FILE $path" . PHP_EOL;
        $lines = @file($path) ?: [];
        echo implode('', array_slice($lines, -40));
    }
}

// laravel log mentions
$laravelLog = __DIR__ . '/storage/logs/laravel.log';
if (is_file($laravelLog)) {
    $lines = @file($laravelLog) ?: [];
    $hit = [];
    foreach ($lines as $line) {
        if (str_contains($line, '309') || str_contains($line, '824') || str_contains($line, '8553') || str_contains($line, 'system.ai.remote') || str_contains($line, 'article.content.generate')) {
            $hit[] = $line;
        }
    }
    echo "LARAVEL_HITS=" . count($hit) . PHP_EOL;
    echo implode('', array_slice($hit, -30));
}

echo "DONE_FORENSIC" . PHP_EOL;
