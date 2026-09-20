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
$svc = app(ArticleEditorLinksPayloadService::class);

$existingFe = [[
    'text' => 'chất liệu',
    'href' => 'https://mayhopphat.com/tin-tuc/vai-oxford-1680d.html',
    'target_url' => 'https://mayhopphat.com/tin-tuc/vai-oxford-1680d.html',
    'destination_resolved' => true,
    'keyword_id' => 3892,
    'source' => 'keyword_non_topic',
]];

$adv = $svc->withAdvancedBatch(
    $article,
    $content,
    $existingFe,
    [],
    ['stage' => 'content_deep', 'offset' => 0],
    5,
    1,
);

$empty = $svc->withAdvancedBatch(
    $article,
    $content,
    [],
    [],
    ['stage' => 'content_deep', 'offset' => 0],
    5,
    0,
);

$methods = get_class_methods($svc);
sort($methods);

echo json_encode([
    'article_site_id' => (int) $article->site_id,
    'methods' => $methods,
    'adv_occupied' => [
        'suggested' => count($adv['suggested_internal_links'] ?? []),
        'texts' => array_map(static fn ($r) => $r['text'] ?? null, $adv['suggested_internal_links'] ?? []),
        'exhausted' => $adv['suggestions_exhausted'] ?? null,
        'cursor' => $adv['suggestion_cursor'] ?? null,
        'fresh' => $adv['suggestion_debug']['fresh_count'] ?? null,
        'deep' => $adv['suggestion_debug']['content_deep'] ?? null,
        'raw_len' => strlen(json_encode($adv, JSON_UNESCAPED_UNICODE)),
        'flat_success_len' => strlen(json_encode(['success' => true] + $adv, JSON_UNESCAPED_UNICODE)),
    ],
    'adv_empty_existing' => [
        'suggested' => count($empty['suggested_internal_links'] ?? []),
        'texts' => array_map(static fn ($r) => $r['text'] ?? null, $empty['suggested_internal_links'] ?? []),
        'fresh' => $empty['suggestion_debug']['fresh_count'] ?? null,
        'deep_valid' => $empty['suggestion_debug']['content_deep']['valid_after_policy'] ?? null,
        'raw_len' => strlen(json_encode($empty, JSON_UNESCAPED_UNICODE)),
        'flat_success_len' => strlen(json_encode(['success' => true] + $empty, JSON_UNESCAPED_UNICODE)),
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
