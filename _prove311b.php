<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use Omnichannel\Addons\Content\Services\ArticleContentConflictGuard;

$article = SeoArticle::query()->findOrFail(8553);
$body = (string) $article->body;
$bodyHash = ArticleContentConflictGuard::contentHash($body);
$pr = DB::connection('omi_seo_ai')->table('prompt_results')->where('id', 2109)->first();
$outText = (string) $pr->output_text;

$prepared = app(PromptTestPublishService::class)->prepareArticleContent($article, $outText);
$html = (string) ($prepared['html'] ?? $prepared[0] ?? '');
if ($html === '' && isset($prepared['content'])) {
    $html = (string) $prepared['content'];
}
// dump keys
echo 'prepare_keys=' . json_encode(array_keys($prepared)) . PHP_EOL;
foreach ($prepared as $k => $v) {
    if (is_string($v)) {
        echo "prep.{$k}_len=" . strlen($v) . " hash=" . hash('sha256', trim($v)) . PHP_EOL;
    }
}

$expected = null;
foreach (['html', 'canonical_html', 'body', 'content'] as $k) {
    if (isset($prepared[$k]) && is_string($prepared[$k]) && trim($prepared[$k]) !== '') {
        $expected = ArticleContentConflictGuard::contentHash($prepared[$k]);
        echo "expected_from={$k} hash={$expected}" . PHP_EOL;
        break;
    }
}

echo "body_hash={$bodyHash}" . PHP_EOL;
echo "body_len=" . strlen($body) . PHP_EOL;
echo "match=" . ($expected !== null && $expected === $bodyHash ? 'YES' : 'NO') . PHP_EOL;

// Also inspect output_snapshot execution_trace for hashes
$ri = DB::connection('omi_seo_ai')->table('seo_project_run_items')->where('id', 826)->first();
$out = json_decode((string)$ri->output_snapshot, true);
$trace = $out['execution_trace'] ?? null;
echo 'trace_type=' . gettype($trace) . PHP_EOL;
if (is_array($trace)) {
    $flat = json_encode($trace);
    foreach (['persist_status','generated','content_hash','body_hash','canonical','applied'] as $needle) {
        if (stripos($flat, $needle) !== false) {
            echo "trace_has_{$needle}=yes" . PHP_EOL;
        }
    }
    // find content node result
    foreach ($out['steps'] ?? [] as $step) {
        if (($step['node_id'] ?? '') === 'node_1780563019334') {
            echo 'content_step=' . json_encode($step, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    }
}

echo "reload_hash=" . ArticleContentConflictGuard::contentHash((string) SeoArticle::query()->findOrFail(8553)->body) . PHP_EOL;
echo "DONE" . PHP_EOL;
