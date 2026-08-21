<?php

declare(strict_types=1);

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DissolveTopicClusterService;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app(SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

$siteId = (int) ($argv[1] ?? 5);
$key = (string) ($argv[2] ?? '');

$result = app(DissolveTopicClusterService::class)->dissolve($siteId, $key);
echo 'success='.($result->success ? '1' : '0').' affected='.$result->affectedKeywordCount.PHP_EOL;
