<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ARTICLE_ID = 8553;
const ITEM_ID = 8799;
const PROJECT_ID = 900;

function h(?string $body): string
{
    return hash('sha256', trim((string) $body));
}

function row(string $label, mixed $value): void
{
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    } elseif (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($value === null) {
        $value = 'null';
    }
    echo $label . '=' . $value . PHP_EOL;
}

echo "=== PHASE1 ARTICLE BASELINE ===" . PHP_EOL;

$articleConn = null;
foreach (['omi_seo_ai', 'mysql'] as $c) {
    try {
        if (Schema::connection($c)->hasTable('articles')) {
            $articleConn = $c;
            break;
        }
    } catch (Throwable $e) {
        // continue
    }
}
row('article_connection', $articleConn);

$article = DB::connection($articleConn)->table('articles')->where('id', ARTICLE_ID)->first();
if (!$article) {
    echo "ARTICLE_NOT_FOUND" . PHP_EOL;
    exit(1);
}

$body = (string) ($article->body ?? $article->content ?? '');
row('article_id', $article->id);
row('title', $article->title ?? null);
row('body_len', strlen($body));
row('body_hash', h($body));
row('updated_at', $article->updated_at ?? null);
row('wp_post_id', $article->wp_post_id ?? ($article->wordpress_post_id ?? null));
row('website_id', $article->website_id ?? null);
row('status', $article->status ?? null);

// project item
$itemConn = 'mysql';
$item = null;
foreach (['content_project_items', 'seo_content_project_items', 'project_items'] as $t) {
    if (Schema::connection($itemConn)->hasTable($t)) {
        $item = DB::connection($itemConn)->table($t)->where('id', ITEM_ID)->first();
        if ($item) {
            row('item_table', $t);
            break;
        }
    }
}
if (!$item && Schema::connection('omi_seo_ai')->hasTable('content_project_items')) {
    $item = DB::connection('omi_seo_ai')->table('content_project_items')->where('id', ITEM_ID)->first();
    if ($item) {
        row('item_table', 'omi_seo_ai.content_project_items');
        $itemConn = 'omi_seo_ai';
    }
}

if ($item) {
    row('item_id', $item->id);
    row('item_project_id', $item->content_project_id ?? ($item->project_id ?? null));
    row('item_article_id', $item->article_id ?? null);
    row('item_task_id', $item->task_id ?? ($item->content_project_task_id ?? null));
    row('item_status', $item->status ?? null);
    row('item_mode', $item->mode ?? ($item->generation_mode ?? null));
    row('item_updated_at', $item->updated_at ?? null);
} else {
    echo "ITEM_NOT_FOUND_TRY_SEARCH" . PHP_EOL;
    // search by article
    foreach (['mysql', 'omi_seo_ai'] as $c) {
        foreach (['content_project_items', 'seo_content_project_items'] as $t) {
            try {
                if (!Schema::connection($c)->hasTable($t)) {
                    continue;
                }
                $found = DB::connection($c)->table($t)->where('article_id', ARTICLE_ID)->orderByDesc('id')->limit(5)->get();
                foreach ($found as $f) {
                    row("found_item_{$c}_{$t}", json_encode($f, JSON_UNESCAPED_UNICODE));
                }
            } catch (Throwable $e) {
                row("err_{$c}_{$t}", $e->getMessage());
            }
        }
    }
}

echo "=== RECENT RUNS / RUN ITEMS ===" . PHP_EOL;
$runTables = [
    'mysql' => ['content_project_runs', 'seo_content_project_runs', 'content_runs'],
    'omi_seo_ai' => ['content_project_runs', 'seo_content_project_runs'],
];
foreach ($runTables as $c => $tables) {
    foreach ($tables as $t) {
        try {
            if (!Schema::connection($c)->hasTable($t)) {
                continue;
            }
            row("run_table", "{$c}.{$t}");
            $cols = Schema::connection($c)->getColumnListing($t);
            $q = DB::connection($c)->table($t);
            if (in_array('content_project_id', $cols, true)) {
                $q->where('content_project_id', PROJECT_ID);
            } elseif (in_array('project_id', $cols, true)) {
                $q->where('project_id', PROJECT_ID);
            }
            $runs = $q->orderByDesc('id')->limit(8)->get();
            foreach ($runs as $r) {
                echo "RUN\t" . json_encode([
                    'id' => $r->id,
                    'status' => $r->status ?? null,
                    'mode' => $r->mode ?? ($r->run_mode ?? null),
                    'from_node' => $r->from_node ?? ($r->start_node ?? null),
                    'created_at' => $r->created_at ?? null,
                    'finished_at' => $r->finished_at ?? ($r->completed_at ?? null),
                ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            }
        } catch (Throwable $e) {
            row("run_err_{$c}_{$t}", $e->getMessage());
        }
    }
}

$runItemTables = [
    'mysql' => ['content_project_run_items', 'seo_content_project_run_items', 'content_run_items'],
    'omi_seo_ai' => ['content_project_run_items', 'seo_content_project_run_items'],
];
foreach ($runItemTables as $c => $tables) {
    foreach ($tables as $t) {
        try {
            if (!Schema::connection($c)->hasTable($t)) {
                continue;
            }
            row('run_item_table', "{$c}.{$t}");
            $cols = Schema::connection($c)->getColumnListing($t);
            $q = DB::connection($c)->table($t);
            if (in_array('article_id', $cols, true)) {
                $q->where('article_id', ARTICLE_ID);
            } elseif (in_array('content_project_item_id', $cols, true)) {
                $q->where('content_project_item_id', ITEM_ID);
            } elseif (in_array('project_item_id', $cols, true)) {
                $q->where('project_item_id', ITEM_ID);
            } else {
                continue;
            }
            $items = $q->orderByDesc('id')->limit(12)->get();
            foreach ($items as $ri) {
                echo "RUN_ITEM\t" . json_encode($ri, JSON_UNESCAPED_UNICODE) . PHP_EOL;
            }
        } catch (Throwable $e) {
            row("ri_err_{$c}_{$t}", $e->getMessage());
        }
    }
}

echo "=== PROMPT RESULTS / LINKS ===" . PHP_EOL;
foreach (['omi_seo_ai', 'mysql'] as $c) {
    foreach (['seo_prompt_results', 'prompt_results'] as $t) {
        try {
            if (!Schema::connection($c)->hasTable($t)) {
                continue;
            }
            row('pr_table', "{$c}.{$t}");
            $cols = Schema::connection($c)->getColumnListing($t);
            $q = DB::connection($c)->table($t);
            if (in_array('article_id', $cols, true)) {
                $q->where('article_id', ARTICLE_ID);
            } else {
                continue;
            }
            $prs = $q->orderByDesc('id')->limit(10)->get(['id', 'article_id', 'prompt_key', 'prompt_type', 'status', 'provider', 'model', 'created_at', 'capability_key', 'connection_id']);
            foreach ($prs as $pr) {
                echo "PR\t" . json_encode($pr, JSON_UNESCAPED_UNICODE) . PHP_EOL;
            }
        } catch (Throwable $e) {
            // try minimal columns
            try {
                $prs = DB::connection($c)->table($t)->where('article_id', ARTICLE_ID)->orderByDesc('id')->limit(10)->get();
                foreach ($prs as $pr) {
                    echo "PR_RAW\t" . json_encode([
                        'id' => $pr->id ?? null,
                        'article_id' => $pr->article_id ?? null,
                        'created_at' => $pr->created_at ?? null,
                    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
                }
            } catch (Throwable $e2) {
                row("pr_err_{$c}_{$t}", $e->getMessage() . ' / ' . $e2->getMessage());
            }
        }
    }
    foreach (['seo_prompt_result_links', 'prompt_result_links'] as $t) {
        try {
            if (!Schema::connection($c)->hasTable($t)) {
                continue;
            }
            row('link_table', "{$c}.{$t}");
            $cols = Schema::connection($c)->getColumnListing($t);
            $q = DB::connection($c)->table($t);
            if (in_array('article_id', $cols, true)) {
                $q->where('article_id', ARTICLE_ID);
            } elseif (in_array('project_item_id', $cols, true)) {
                $q->where('project_item_id', ITEM_ID);
            } else {
                $links = $q->orderByDesc('id')->limit(5)->get();
                continue;
            }
            $links = $q->orderByDesc('id')->limit(15)->get();
            foreach ($links as $l) {
                echo "LINK\t" . json_encode($l, JSON_UNESCAPED_UNICODE) . PHP_EOL;
            }
        } catch (Throwable $e) {
            row("link_err_{$c}_{$t}", $e->getMessage());
        }
    }
}

echo "=== AI CONNECTIONS SNAPSHOT (no provider call) ===" . PHP_EOL;
foreach (['omi_seo_ai', 'mysql'] as $c) {
    if (!Schema::connection($c)->hasTable('seo_ai_api_connections') && !Schema::connection($c)->hasTable('ai_api_connections')) {
        continue;
    }
    $t = Schema::connection($c)->hasTable('seo_ai_api_connections') ? 'seo_ai_api_connections' : 'ai_api_connections';
    row('conn_table', "{$c}.{$t}");
    $conns = DB::connection($c)->table($t)->orderBy('id')->get();
    foreach ($conns as $cn) {
        echo "CONN\t" . json_encode([
            'id' => $cn->id,
            'name' => $cn->name ?? ($cn->label ?? null),
            'provider' => $cn->provider ?? ($cn->provider_key ?? null),
            'is_active' => $cn->is_active ?? ($cn->active ?? null),
            'health_status' => $cn->health_status ?? null,
            'paid_locked' => $cn->paid_locked ?? ($cn->is_paid_locked ?? null),
            'balance' => $cn->balance ?? ($cn->wallet_balance ?? null),
            'balance_updated_at' => $cn->balance_updated_at ?? ($cn->wallet_checked_at ?? null),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}

echo "=== MODEL COUNT ===" . PHP_EOL;
foreach (['omi_seo_ai', 'mysql'] as $c) {
    if (Schema::connection($c)->hasTable('seo_ai_models')) {
        row("seo_ai_models_{$c}", DB::connection($c)->table('seo_ai_models')->count());
    }
}

echo "DONE_PHASE1" . PHP_EOL;
