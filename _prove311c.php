<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;

$article = SeoArticle::query()->findOrFail(8553);
$beforeNote = 'baseline_before_311 was dae822b694969f78a76e510f56b09d8a4b56b15d5b6eb76910f77efeb278946c';
$body = (string) $article->body;
$publisher = app(PromptTestPublishService::class);
$bodyHash = $publisher->contentHash($body);

$pr = DB::connection('omi_seo_ai')->table('prompt_results')->where('id', 2109)->first();
$outText = (string) $pr->output_text;
$prepared = $publisher->prepareArticleContent($article, $outText);
echo 'prepare_keys=' . json_encode(array_keys($prepared)) . PHP_EOL;
$html = (string) ($prepared['html'] ?? '');
$expected = $publisher->contentHash($html);
echo 'prepared_html_len=' . strlen($html) . PHP_EOL;
echo 'expected_canonical_hash=' . $expected . PHP_EOL;
echo 'body_hash_now=' . $bodyHash . PHP_EOL;
echo 'body_len=' . strlen($body) . PHP_EOL;
echo 'hash_equal=' . ($expected === $bodyHash ? 'YES' : 'NO') . PHP_EOL;

$fresh = SeoArticle::query()->findOrFail(8553);
$reloadHash = $publisher->contentHash((string) $fresh->body);
echo 'reload_hash=' . $reloadHash . PHP_EOL;
echo 'reload_equal=' . ($expected === $reloadHash ? 'YES' : 'NO') . PHP_EOL;
echo $beforeNote . PHP_EOL;

$ri = DB::connection('omi_seo_ai')->table('seo_project_run_items')->where('id', 826)->first();
$out = json_decode((string) $ri->output_snapshot, true);
echo 'persist_status=' . ($out['persist_status'] ?? '') . PHP_EOL;
foreach ($out['steps'] ?? [] as $step) {
    if (($step['node_id'] ?? '') === 'node_1780563019334') {
        echo 'content_node=' . json_encode($step, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
echo 'DONE' . PHP_EOL;
