<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function trunc($v, int $n = 180): mixed
{
    if (!is_string($v)) {
        return $v;
    }
    return strlen($v) > $n ? substr($v, 0, $n) . '...[' . strlen($v) . ']' : $v;
}

echo "=== TASK 8799 ===" . PHP_EOL;
$task = DB::connection('omi_seo_ai')->table('seo_project_tasks')->where('id', 8799)->first();
$ta = (array) $task;
foreach ($ta as $k => $v) {
    if (is_string($v) && strlen($v) > 300) {
        $ta[$k] = trunc($v, 120);
    }
}
echo json_encode($ta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

echo "=== RUN ITEMS 821-823 FULL ===" . PHP_EOL;
$ris = DB::connection('omi_seo_ai')->table('seo_project_run_items')->whereIn('id', [821, 822, 823])->orderBy('id')->get();
$cols = Schema::connection('omi_seo_ai')->getColumnListing('seo_project_run_items');
echo 'ri_cols=' . implode(',', $cols) . PHP_EOL;
foreach ($ris as $ri) {
    $a = (array) $ri;
    foreach ($a as $k => $v) {
        if (is_string($v) && strlen($v) > 500) {
            $a[$k] = trunc($v, 250);
        }
    }
    echo "RI\t" . json_encode($a, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== PR 2107 + routing attempts ===" . PHP_EOL;
$pr = DB::connection('omi_seo_ai')->table('prompt_results')->where('id', 2107)->first();
if ($pr) {
    $a = (array) $pr;
    foreach (['input_snapshot', 'output_text'] as $h) {
        if (isset($a[$h]) && is_string($a[$h])) {
            $a[$h . '_len'] = strlen($a[$h]);
            $a[$h . '_hash'] = hash('sha256', trim($a[$h]));
            $a[$h] = trunc($a[$h], 150);
        }
    }
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo "MISSING_2107" . PHP_EOL;
}

$attempts = DB::connection('omi_seo_ai')->table('prompt_result_routing_attempts')->where('prompt_result_id', 2107)->orderBy('id')->get();
echo 'attempts_count=' . $attempts->count() . PHP_EOL;
foreach ($attempts as $at) {
    $a = (array) $at;
    foreach ($a as $k => $v) {
        if (is_string($v) && strlen($v) > 300) {
            $a[$k] = trunc($v, 120);
        }
    }
    echo "ATTEMPT\t" . json_encode($a, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== API CONNECTIONS (mysql + omi_seo_ai) ===" . PHP_EOL;
foreach (['mysql', 'omi_seo_ai'] as $c) {
    if (!Schema::connection($c)->hasTable('api_connections')) {
        continue;
    }
    $cols = Schema::connection($c)->getColumnListing('api_connections');
    echo "api_connections_cols_{$c}=" . implode(',', $cols) . PHP_EOL;
    $rows = DB::connection($c)->table('api_connections')->orderBy('id')->get();
    foreach ($rows as $r) {
        $a = (array) $r;
        // redact secrets
        foreach ($a as $k => $v) {
            if (is_string($k) && preg_match('/token|secret|key|password|api_key/i', $k) && is_string($v) && $v !== '') {
                $a[$k] = '***SET***';
            }
            if (is_string($v) && strlen($v) > 200) {
                $a[$k] = trunc($v, 80);
            }
        }
        echo "CONN_{$c}\t" . json_encode($a, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}

echo "=== ARTICLE HASH CHECK vs PR output ===" . PHP_EOL;
$article = DB::connection('omi_seo_ai')->table('articles')->where('id', 8553)->first();
$body = (string) ($article->body ?? '');
echo 'body_len=' . strlen($body) . PHP_EOL;
echo 'body_hash=' . hash('sha256', trim($body)) . PHP_EOL;
if ($pr && isset($pr->output_text)) {
    // compare with prepared content if possible later
    echo 'pr_output_len=' . strlen((string) $pr->output_text) . PHP_EOL;
    echo 'pr_output_hash=' . hash('sha256', trim((string) $pr->output_text)) . PHP_EOL;
}

echo "DONE" . PHP_EOL;
