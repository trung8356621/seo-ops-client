<?php

declare(strict_types=1);

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$article = SeoArticle::query()->with(['site', 'articleMetas'])->find(11158);
$content = app(SeoAnalyzerService::class)->resolveScoringContentForArticle($article);

// Simulate FE after Normal: only actionable occupied rows (not full catalog).
$existing = [
    [
        'text' => 'chất liệu',
        'href' => 'https://mayhopphat.com/tin-tuc/vai-oxford-1680d.html',
        'target_url' => 'https://mayhopphat.com/tin-tuc/vai-oxford-1680d.html',
        'destination_resolved' => true,
        'keyword_id' => null,
        'source' => null,
    ],
    [
        'text' => 'quà tặng',
        'href' => 'https://mayhopphat.com/tui-qua-tang/', // placeholder — will also try without if unknown
        'target_url' => 'https://mayhopphat.com/tui-qua-tang/',
        'destination_resolved' => true,
        'keyword_id' => null,
        'source' => null,
    ],
];

// Resolve quà tặng href from a fresh full suggest if possible
$full = app(\Omnichannel\Addons\Content\Services\ArticleInternalLinkSuggestionService::class)
    ->suggestBundle($article, $content, [], []);
foreach (($full['internal_catalog'] ?? []) as $row) {
    $t = mb_strtolower(trim((string) ($row['text'] ?? '')));
    if ($t === 'quà tặng' || $t === 'qua tang') {
        $existing[1]['href'] = (string) ($row['href'] ?? $existing[1]['href']);
        $existing[1]['target_url'] = (string) ($row['target_url'] ?? $existing[1]['href']);
        $existing[1]['keyword_id'] = $row['keyword_id'] ?? null;
        $existing[1]['source'] = $row['source'] ?? null;
    }
    if ($t === 'chất liệu' || $t === 'chat lieu') {
        $existing[0]['href'] = (string) ($row['href'] ?? $existing[0]['href']);
        $existing[0]['target_url'] = (string) ($row['target_url'] ?? $existing[0]['href']);
        $existing[0]['keyword_id'] = $row['keyword_id'] ?? null;
        $existing[0]['source'] = $row['source'] ?? null;
    }
}

$payload = app(ArticleEditorLinksPayloadService::class)->withAdvancedBatch(
    $article,
    $content,
    $existing,
    [],
    ['stage' => 'content_deep', 'offset' => 0],
    5,
    2, // usable_count like FE
);

$internal = $payload['suggested_internal_links'] ?? [];
$catalog = $payload['suggested_internal_links_catalog'] ?? [];
$debug = $payload['suggestion_debug'] ?? [];
$deep = is_array($debug['content_deep'] ?? null) ? $debug['content_deep'] : [];

echo json_encode([
    'note' => 'FE-shaped replay (not live browser capture) — existing occupied = chất liệu + quà tặng, usable_count=2, cursor=content_deep/0',
    'existing' => $existing,
    'http_payload_keys' => array_keys($payload),
    'suggested_internal_count' => count($internal),
    'suggested_catalog_count' => count($catalog),
    'suggestions_exhausted' => $payload['suggestions_exhausted'] ?? null,
    'suggestion_cursor' => $payload['suggestion_cursor'] ?? null,
    'failed_candidate_keys_count' => count($payload['failed_candidate_keys'] ?? []),
    'debug_usable' => [
        'usable_count' => $debug['usable_count'] ?? null,
        'remaining_slots' => $debug['remaining_slots'] ?? null,
        'batch_target' => $debug['batch_target'] ?? null,
        'fresh_count' => $debug['fresh_count'] ?? null,
        'skip_reason' => $debug['skip_reason'] ?? null,
    ],
    'content_deep_funnel' => [
        'extracted_phrase_count' => $deep['extracted_phrase_count'] ?? null,
        'phrases_with_keyword_target' => $deep['phrases_with_keyword_target'] ?? null,
        'phrases_searched_index' => $deep['phrases_searched_index'] ?? null,
        'destination_candidates' => $deep['destination_candidates'] ?? null,
        'valid_after_policy' => $deep['valid_after_policy'] ?? null,
        'no_candidate' => $deep['no_candidate'] ?? null,
        'fresh_count' => $deep['fresh_count'] ?? null,
        'exhausted' => $deep['exhausted'] ?? null,
    ],
    'new_suggestions' => array_map(static fn ($r) => [
        'text' => $r['text'] ?? null,
        'href' => $r['href'] ?? null,
        'source' => $r['candidate_source'] ?? $r['source'] ?? null,
        'destination_resolved' => $r['destination_resolved'] ?? null,
        'keyword_id' => $r['keyword_id'] ?? null,
        'match_reason' => $r['match_reason'] ?? null,
    ], $internal),
    'catalog_texts' => array_map(static fn ($r) => $r['text'] ?? null, $catalog),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

// Also empty-existing replay = prior CLI semantics
$empty = app(ArticleEditorLinksPayloadService::class)->withAdvancedBatch(
    $article,
    $content,
    [],
    [],
    ['stage' => 'content_deep', 'offset' => 0],
    5,
    2,
);
echo "\n--- EMPTY existing_internal (prior CLI style) ---\n";
echo json_encode([
    'suggested_count' => count($empty['suggested_internal_links'] ?? []),
    'texts' => array_map(static fn ($r) => $r['text'] ?? null, $empty['suggested_internal_links'] ?? []),
    'valid_after_policy' => $empty['suggestion_debug']['content_deep']['valid_after_policy'] ?? null,
    'fresh_count' => $empty['suggestion_debug']['content_deep']['fresh_count'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

$fullPayload = app(ArticleEditorLinksPayloadService::class)->buildInitial($article, $content);
// Prefer withFullBundle if exists
try {
  $ref = new ReflectionClass(ArticleEditorLinksPayloadService::class);
} catch (Throwable $e) {}

function envelopeSize(array $payload): int {
    return strlen(json_encode([
        "success" => true,
        "data" => $payload,
    ], JSON_UNESCAPED_UNICODE));
}
function envelopeSizeFlat(array $payload): int {
    return strlen(json_encode(array_merge(["success" => true], $payload), JSON_UNESCAPED_UNICODE));
}

echo "\n--- SIZE PROBE ---\n";
echo json_encode([
  "occupied_flat" => envelopeSizeFlat($payload),
  "occupied_nested" => envelopeSize($payload),
  "empty_existing_flat" => envelopeSizeFlat($empty),
  "empty_existing_nested" => envelopeSize($empty),
  "raw_occupied_only" => strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)),
  "raw_empty_only" => strlen(json_encode($empty, JSON_UNESCAPED_UNICODE)),
], JSON_PRETTY_PRINT), PHP_EOL;

// Realistic FE existing: only ch?t li?u (qu� t?ng # skipped by buildExistingInternalPayload)
$existingChatOnly = array_values(array_filter($existing, static fn ($r) => mb_strtolower($r["text"]) === "ch?t li?u"));
// Also if qu� t?ng href is #, FE skips it
$existingFe = [];
foreach ($existing as $row) {
  $href = (string)($row["href"] ?? "");
  if ($href === "" || $href === "#" || str_starts_with($href, "#")) {
    continue;
  }
  $existingFe[] = $row;
}
$fePayload = app(ArticleEditorLinksPayloadService::class)->withAdvancedBatch(
  $article, $content, $existingFe, [], ["stage"=>"content_deep","offset"=>0], 5, count($existingFe)
);
echo "\n--- FE-REALISTIC existing (skip # href) ---\n";
echo json_encode([
  "existing_fe" => $existingFe,
  "suggested_count" => count($fePayload["suggested_internal_links"] ?? []),
  "texts" => array_map(static fn($r)=>$r["text"]??null, $fePayload["suggested_internal_links"] ?? []),
  "exhausted" => $fePayload["suggestions_exhausted"] ?? null,
  "cursor" => $fePayload["suggestion_cursor"] ?? null,
  "fresh_count" => $fePayload["suggestion_debug"]["fresh_count"] ?? null,
  "content_deep" => $fePayload["suggestion_debug"]["content_deep"] ?? null,
  "size_flat" => envelopeSizeFlat($fePayload),
  "size_raw" => strlen(json_encode($fePayload, JSON_UNESCAPED_UNICODE)),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
