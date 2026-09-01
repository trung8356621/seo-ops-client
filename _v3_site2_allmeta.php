<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = App\Models\Site::query()->with('metas')->find(2);
foreach ($s->metas as $m) {
    $k = (string) $m->meta_key;
    if (preg_match('/token|write|read|pass|auth|bridge|laravel/i', $k)) {
        $v = trim((string) $m->meta_value);
        $show = preg_match('/token|pass|secret/i', $k)
            ? ($v !== '' ? 'SET('.strlen($v).')' : 'EMPTY')
            : $v;
        echo $k.'='.$show.PHP_EOL;
    }
}
