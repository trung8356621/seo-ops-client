<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = App\Models\Site::query()->find(2);
foreach (['seo_write_token', 'seo_read_token', 'seo_laravel_url', 'laravel_api_url', 'omi_laravel_url'] as $k) {
    $v = trim((string) ($s->getMeta($k) ?? ''));
    if (str_contains($k, 'token')) {
        echo $k.'='.($v !== '' ? 'SET('.strlen($v).')' : 'EMPTY').PHP_EOL;
    } else {
        echo $k.'='.$v.PHP_EOL;
    }
}
