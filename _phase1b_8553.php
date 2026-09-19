<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function tables(string $conn, string $like): void
{
    $rows = DB::connection($conn)->select('SHOW TABLES');
    $key = 'Tables_in_' . DB::connection($conn)->getDatabaseName();
    foreach ($rows as $r) {
        $name = $r->$key ?? reset((array) $r);
        if ($like === '' || stripos((string) $name, $like) !== false) {
            echo "TABLE\t{$conn}\t{$name}" . PHP_EOL;
        }
    }
}

echo "=== TABLES LIKE project/run/article/ai ===" . PHP_EOL;
foreach (['mysql', 'omi_seo_ai'] as $c) {
    tables($c, 'project');
    tables($c, 'run');
    tables($c, 'prompt');
    tables($c, 'ai_');
    tables($c, 'api_connection');
    tables($c, 'connection');
}

echo "=== LOOKUP ITEM/TASK 8799 ===" . PHP_EOL;
foreach (['mysql', 'omi_seo_ai'] as $c) {
    $rows = DB::connection($c)->select('SHOW TABLES');
    $key = 'Tables_in_' . DB::connection($c)->getDatabaseName();
    foreach ($rows as $r) {
        $name = (string) ($r->$key ?? reset((array) $r));
        if (!preg_match('/(project|task|item|run)/i', $name)) {
            continue;
        }
        try {
            $cols = Schema::connection($c)->getColumnListing($name);
            $q = DB::connection($c)->table($name);
            $matched = false;
            if (in_array('id', $cols, true)) {
                $hit = (clone $q)->where('id', 8799)->first();
                if ($hit) {
                    echo "HIT_ID\t{$c}.{$name}\t" . json_encode($hit, JSON_UNESCAPED_UNICODE) . PHP_EOL;
                    $matched = true;
                }
            }
            foreach (['project_task_id', 'task_id', 'content_project_item_id', 'project_item_id', 'item_id'] as $col) {
                if (in_array($col, $cols, true)) {
                    $hits = DB::connection($c)->table($name)->where($col, 8799)->orderByDesc('id')->limit(3)->get();
                    foreach ($hits as $h) {
                        echo "HIT_COL\t{$c}.{$name}.{$col}\t" . json_encode($h, JSON_UNESCAPED_UNICODE) . PHP_EOL;
                    }
                }
            }
            if (in_array('article_id', $cols, true) && preg_match('/(run_item|project_item|task)/i', $name)) {
                $hits = DB::connection($c)->table($name)->where('article_id', 8553)->orderByDesc('id')->limit(5)->get();
                foreach ($hits as $h) {
                    echo "HIT_ART\t{$c}.{$name}\t" . json_encode($h, JSON_UNESCAPED_UNICODE) . PHP_EOL;
                }
            }
        } catch (Throwable $e) {
            echo "ERR\t{$c}.{$name}\t" . $e->getMessage() . PHP_EOL;
        }
    }
}

echo "=== RUN 307 + RECENT RUNS FOR PROJECT 900 ===" . PHP_EOL;
foreach (['mysql', 'omi_seo_ai'] as $c) {
    $rows = DB::connection($c)->select('SHOW TABLES');
    $key = 'Tables_in_' . DB::connection($c)->getDatabaseName();
    foreach ($rows as $r) {
        $name = (string) ($r->$key ?? reset((array) $r));
        if (!preg_match('/run/i', $name) || preg_match('/run_item|attempt|migration/i', $name)) {
            continue;
        }
        try {
            $cols = Schema::connection($c)->getColumnListing($name);
            if (!in_array('id', $cols, true)) {
                continue;
            }
            $run = DB::connection($c)->table($name)->where('id', 307)->first();
            if ($run) {
                echo "RUN307\t{$c}.{$name}\t" . json_encode($run, JSON_UNESCAPED_UNICODE) . PHP_EOL;
            }
            if (in_array('content_project_id', $cols, true) || in_array('project_id', $cols, true)) {
                $col = in_array('content_project_id', $cols, true) ? 'content_project_id' : 'project_id';
                $runs = DB::connection($c)->table($name)->where($col, 900)->orderByDesc('id')->limit(10)->get();
                foreach ($runs as $rr) {
                    echo "PRJ_RUN\t{$c}.{$name}\t" . json_encode($rr, JSON_UNESCAPED_UNICODE) . PHP_EOL;
                }
            }
        } catch (Throwable $e) {
            echo "ERR2\t{$c}.{$name}\t" . $e->getMessage() . PHP_EOL;
        }
    }
}

echo "=== PR 2107 DETAIL ===" . PHP_EOL;
$pr = DB::connection('omi_seo_ai')->table('prompt_results')->where('id', 2107)->first();
if ($pr) {
    $arr = (array) $pr;
    foreach (['output', 'result', 'content', 'response', 'raw_output', 'prompt_input', 'input'] as $heavy) {
        if (isset($arr[$heavy]) && is_string($arr[$heavy])) {
            $arr[$heavy . '_len'] = strlen($arr[$heavy]);
            $arr[$heavy] = substr($arr[$heavy], 0, 120) . '...';
        }
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo "PR2107_MISSING" . PHP_EOL;
}

echo "=== LATEST PR FOR ARTICLE ===" . PHP_EOL;
$cols = Schema::connection('omi_seo_ai')->getColumnListing('prompt_results');
echo 'pr_cols=' . implode(',', $cols) . PHP_EOL;
$prs = DB::connection('omi_seo_ai')->table('prompt_results')->where('article_id', 8553)->orderByDesc('id')->limit(5)->get();
foreach ($prs as $p) {
    $a = (array) $p;
    foreach ($a as $k => $v) {
        if (is_string($v) && strlen($v) > 200) {
            $a[$k] = substr($v, 0, 100) . '...[' . strlen($v) . ']';
        }
    }
    echo "PR\t" . json_encode($a, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "DONE" . PHP_EOL;
