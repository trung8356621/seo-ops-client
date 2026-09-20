<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback;
use Omnichannel\Addons\Content\Services\LinksPayloadService;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;

$article = SeoArticle::query()->with(['site','articleMetas'])->find(11158);
$userId = (int) ($article->user_id ?? 0);
$user = $userId > 0 ? User::query()->find($userId) : User::query()->first();
if ($user) { Auth::login($user); }

$content = (string) ($article->content ?? '');
$analyzer = app(SeoAnalyzerService::class);
$plain = method_exists($analyzer, 'extractPlainText')
    ? (string) $analyzer->extractPlainText($content)
    : strip_tags($content);

$occupied = [
    ['text' => 'chất liệu', 'href' => 'https://example.com/a'],
    ['text' => 'quà tặng', 'href' => 'https://example.com/b'],
];

$fallback = app(ArticleLinkSuggestionContentKeywordFallback::class);
$result = $fallback->suggestAdvanced($article, $plain, [
    'target_count' => 5,
    'usable_count' => 2,
    'existing_internal' => $occupied,
    'cursor' => ['stage' => 'content_deep', 'offset' => 0, 'phrase_offset' => 0],
    'failed_keys' => [],
]);

$suggestions = $result['suggestions'] ?? $result['items'] ?? [];
$debug = $result['debug'] ?? $fallback->getLastDebug?.() ?? null;
if (method_exists($fallback, 'lastDebug') || true) {
    $ref = new ReflectionClass($fallback);
    if ($ref->hasProperty('lastDebug')) {
        $p = $ref->getProperty('lastDebug');
        $p->setAccessible(true);
        $debug = $p->getValue($fallback);
    }
}

$out = [
    'fresh_count' => is_array($suggestions) ? count($suggestions) : 0,
    'suggestions' => array_map(static function ($row) {
        return [
            'text' => $row['text'] ?? $row['anchor'] ?? null,
            'href' => $row['href'] ?? $row['target_url'] ?? null,
            'target_url' => $row['target_url'] ?? $row['href'] ?? null,
            'score' => $row['score'] ?? null,
            'source' => $row['source'] ?? $row['suggestion_source'] ?? null,
            'keyword_id' => $row['keyword_id'] ?? null,
            'destination_resolved' => $row['destination_resolved'] ?? null,
        ];
    }, is_array($suggestions) ? $suggestions : []),
    'debug_keys' => is_array($debug) ? array_keys($debug) : [],
    'fresh_after_occupied_dedupe' => $debug['fresh_after_occupied_dedupe'] ?? $debug['fresh_count'] ?? null,
    'cursor' => $result['cursor'] ?? $debug['cursor'] ?? null,
    'content_length' => strlen($content),
    'plain_length' => mb_strlen($plain),
    'content_sample_has_tui' => (bool) preg_match('/Túi\s*xách/u', $content),
    'content_sample_has_do_ben' => (bool) preg_match('/Độ\s*bền/u', $content),
    'plain_has_tui' => mb_stripos($plain, 'Túi xách') !== false || mb_stripos($plain, 'túi xách') !== false,
    'plain_has_do_ben' => mb_stripos($plain, 'Độ bền') !== false || mb_stripos($plain, 'độ bền') !== false,
];

// Also shape as HTTP-like payload fields used by normalizeLinksPayload
$out['http_shape'] = [
    'suggested_internal_links' => $out['suggestions'],
    'suggested_internal_links_catalog' => $out['suggestions'],
];

file_put_contents(__DIR__.'/_fe_funnel_payload_11158.json', json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
echo json_encode([
    'fresh' => $out['fresh_count'],
    'texts' => array_column($out['suggestions'], 'text'),
    'fresh_metric' => $out['fresh_after_occupied_dedupe'],
    'cursor' => $out['cursor'],
], JSON_UNESCAPED_UNICODE);