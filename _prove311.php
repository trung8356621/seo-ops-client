<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use Omnichannel\Addons\Content\Services\ArticleContentConflictGuard;

function trunc($v,$n=160){ return is_string($v)&&strlen($v)>$n?substr($v,0,$n).'...['.strlen($v).']':$v; }

$ri = DB::connection('omi_seo_ai')->table('seo_project_run_items')->where('id', 826)->first();
$run = DB::connection('omi_seo_ai')->table('seo_project_runs')->where('id', 311)->first();
$task = DB::connection('omi_seo_ai')->table('seo_project_tasks')->where('id', 8799)->first();
$article = DB::connection('omi_seo_ai')->table('articles')->where('id', 8553)->first();
$body = (string)($article->body ?? '');
$bodyHash = hash('sha256', trim($body));

echo "=== TERMINAL ===" . PHP_EOL;
echo json_encode([
    'run_id' => 311,
    'run_status' => $run->status,
    'succeeded' => $run->succeeded,
    'failed' => $run->failed,
    'ri_id' => 826,
    'ri_status' => $ri->status,
    'ri_error_code' => $ri->error_code,
    'ri_error_message' => $ri->error_message,
    'ri_message' => $ri->message,
    'task_status' => $task->status,
    'body_len' => strlen($body),
    'body_hash' => $bodyHash,
    'updated_at' => $article->updated_at,
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

$out = json_decode((string)($ri->output_snapshot ?? ''), true);
echo "OUTPUT_KEYS=" . json_encode(is_array($out)?array_keys($out):null) . PHP_EOL;
if (is_array($out)) {
    foreach (['persist_status','ancillary_status','result_id','prompt_result_id','generated_hash','body_hash','content_hash','ancillary_failures','conflict_status','stale_status'] as $k) {
        if (array_key_exists($k, $out)) echo "OUT.$k=".json_encode($out[$k], JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
    // deep search persist
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);
    if (preg_match('/persist_status[^,]{0,40}/', $json, $m)) echo "PERSIST_SNIP={$m[0]}" . PHP_EOL;
    echo "OUT_PREVIEW=".trunc($json, 2000).PHP_EOL;
}

$pr = DB::connection('omi_seo_ai')->table('prompt_results')->where('id', 2109)->first();
$pra = (array)$pr;
$outText = (string)($pr->output_text ?? '');
echo "=== PR2109 ===" . PHP_EOL;
echo json_encode([
    'id' => $pr->id,
    'status' => $pr->status,
    'canonical_prompt_key' => $pr->canonical_prompt_key,
    'article_id_col' => $pr->article_id ?? null,
    'content_project_id' => $pr->content_project_id,
    'project_item_id' => $pr->project_item_id,
    'run_id' => $pr->run_id,
    'output_len' => strlen($outText),
    'output_hash_raw' => hash('sha256', trim($outText)),
    'started_at' => $pr->started_at,
    'finished_at' => $pr->finished_at,
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

// Canonical prepared HTML hash if service available
try {
    $prepared = app(PromptTestPublishService::class)->prepareArticleContent($outText);
    $prepHash = hash('sha256', trim((string)$prepared));
    echo "prepared_html_len=".strlen((string)$prepared).PHP_EOL;
    echo "prepared_html_hash={$prepHash}" . PHP_EOL;
    echo "body_equals_prepared=" . ($bodyHash === $prepHash ? 'YES' : 'NO') . PHP_EOL;
    // also try contentHash helper
    if (class_exists(ArticleContentConflictGuard::class)) {
        $guardHash = ArticleContentConflictGuard::contentHash($body);
        echo "guard_body_hash={$guardHash}" . PHP_EOL;
        echo "guard_equals_prepared=" . ($guardHash === $prepHash ? 'YES' : 'NO') . PHP_EOL;
    }
} catch (Throwable $e) {
    echo "prepare_err=".$e->getMessage().PHP_EOL;
    // fallback: direct equality body vs output
    echo "body_equals_raw_output=" . ($bodyHash === hash('sha256', trim($outText)) ? 'YES' : 'NO') . PHP_EOL;
}

$links = DB::connection('omi_seo_ai')->table('seo_prompt_result_links')->where('prompt_result_id', 2109)->orderBy('id')->get();
foreach ($links as $l) echo "LINK\t".json_encode($l, JSON_UNESCAPED_UNICODE).PHP_EOL;

$atts = DB::connection('omi_seo_ai')->table('prompt_result_routing_attempts')->where('prompt_result_id', 2109)->orderBy('id')->get();
echo "attempts=".$atts->count().PHP_EOL;
foreach ($atts as $at) {
    echo "ATTEMPT\t".json_encode([
        'seq' => $at->sequence,
        'logical' => $at->logical_model,
        'provider' => $at->provider,
        'conn' => $at->connection_id,
        'conn_name' => $at->connection_name,
        'model' => $at->provider_model,
        'cost' => $at->cost_class,
        'state' => $at->state,
        'attempted' => $at->attempted,
        'skip' => $at->skip_reason,
        'http' => $at->http_status,
        'fail' => $at->failure_code,
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
}

// WP publish check - no new publish attempts
$pub = DB::connection('omi_seo_ai')->table('seo_content_project_publish_attempts')->where('created_at','>=','2026-09-19 07:25:00')->count();
echo "publish_attempts_since_run=". $pub . PHP_EOL;
echo "task_publish_queue_status=".($task->publish_queue_status ?? '').PHP_EOL;

// confirm no WP pull during this run
echo "DONE_PROVE" . PHP_EOL;
