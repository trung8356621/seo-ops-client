<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner;
use Omnichannel\Addons\AiPrompt\Services\WritingSectionPromptCompiler;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
if ($rec) {
    app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
}

$full = trim((string) PromptResult::query()->findOrFail(1445)->output_text);
$vocab = trim((string) PromptResult::query()->findOrFail(1446)->output_text);
$prompt = SeoPrompt::query()->findOrFail(5);
$rows = (new OutlineStructuredRowsNormalizer())->normalize($full);
$plan = (new WritingMultiplePassStepPlanner())->planFromRows($rows);
$unit = $plan->units[0];
$vars = [
    'title' => 'x',
    'keyword' => 'Effortless Chic',
    'language' => 'vi',
    'article_outline' => $full,
    'outline' => $full,
    'input' => $full,
    'article_writing_raw_input' => $full,
    'article_vocabulary' => $vocab,
    'generation_shape' => 'sectioned',
    'generation_strategy' => 'sectioned',
];
$compiled = app(WritingSectionPromptCompiler::class)->compile($prompt, $vars, $unit);
preg_match_all('/^.*## .*$/mu', $compiled, $m);
$lines = $m[0];
echo 'count='.count($lines).PHP_EOL;
foreach ($lines as $i => $line) {
    echo ($i + 1).': '.mb_substr($line, 0, 140).PHP_EOL;
}
preg_match_all('/^##\s+(.+)$/mu', $full, $oh);
echo 'outline_h2_titles='.json_encode($oh[1], JSON_UNESCAPED_UNICODE).PHP_EOL;
$titleHits = [];
foreach ($oh[1] as $t) {
    $hit = str_contains($compiled, '## '.$t);
    $titleHits[] = ['title' => mb_substr($t, 0, 80), 'as_h2' => $hit];
    echo 'title_as_h2_in_compiled='.($hit ? 'YES' : 'no').' '.mb_substr($t, 0, 60).PHP_EOL;
}

// Also check raw prompt part content for ## before substitution
$rawHashes = [];
foreach ($prompt->resolvedParts() as $part) {
    if ((string) $part->role === 'global_constraints') {
        continue;
    }
    $c = (string) $part->content;
    preg_match_all('/^.*## .*$/mu', $c, $pm);
    foreach ($pm[0] as $line) {
        $rawHashes[] = mb_substr($line, 0, 140);
    }
}
echo 'raw_part_hash_lines='.count($rawHashes).PHP_EOL;
foreach ($rawHashes as $i => $line) {
    echo 'RAW'.($i + 1).': '.$line.PHP_EOL;
}

$payload = [
    'sessionId' => 'd04245',
    'runId' => 'isolation-trace',
    'hypothesisId' => 'H6',
    'location' => 'isolation_hash_dump.php',
    'message' => 'compiled_hash_line_sources',
    'data' => [
        'compiled_hash_lines' => array_map(static fn (string $l): string => mb_substr($l, 0, 160), $lines),
        'raw_part_hash_lines' => $rawHashes,
        'outline_title_as_h2' => $titleHits,
        'slice_preview' => mb_substr($unit->scopeMarkdown(), 0, 200),
    ],
    'timestamp' => (int) round(microtime(true) * 1000),
];
file_put_contents(__DIR__.'/debug-d04245.log', json_encode($payload, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
