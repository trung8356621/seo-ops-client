<?php

declare(strict_types=1);

/**
 * One-off READ-ONLY export of Topic candidate keywords for semantic quality work.
 * Does not mutate Topics. Delete after use or keep under scripts/dev.
 */

use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSiteKeywordService;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$siteId = (int) ($argv[1] ?? 4);
$out = (string) ($argv[2] ?? 'D:/work/seo-ops-semantic/artifacts/local/site4_keywords.json');

$svc = $app->make(TopicSiteKeywordService::class);
$rows = $svc->loadTopicCandidateKeywords($siteId);
$payload = [
    'site_ref' => (string) $siteId,
    'language' => null,
    'keywords' => array_map(
        static fn (array $r): array => [
            'ref' => (string) $r['keyword_id'],
            'text' => (string) $r['phrase'],
        ],
        $rows,
    ),
];

$dir = dirname($out);
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}
file_put_contents($out, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fwrite(STDOUT, 'wrote '.$out.' count='.count($rows).PHP_EOL);
