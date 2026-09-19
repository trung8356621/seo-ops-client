<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'APP_ENV=' . config('app.env') . PHP_EOL;
echo 'APP_DEBUG=' . (config('app.debug') ? 'true' : 'false') . PHP_EOL;
echo 'DB_DEFAULT=' . config('database.default') . PHP_EOL;
echo 'DB_DATABASE=' . config('database.connections.mysql.database') . PHP_EOL;
echo 'DB_HOST=' . config('database.connections.mysql.host') . PHP_EOL;

foreach (['omi_seo_ai', 'seo', 'mysql_seo'] as $conn) {
    $db = config("database.connections.{$conn}.database");
    if ($db) {
        echo "DB_{$conn}=" . $db . PHP_EOL;
    }
}

echo 'QUEUE_CONNECTION=' . config('queue.default') . PHP_EOL;
echo 'SYSTEM_API_BASE_URL=' . (env('SYSTEM_API_BASE_URL') ?: '(none)') . PHP_EOL;
echo 'SYSTEM_API_TOKEN_SET=' . (env('SYSTEM_API_TOKEN') ? 'yes' : 'no') . PHP_EOL;

foreach ($_ENV as $k => $v) {
    if (str_starts_with((string) $k, 'SYSTEM_CAP_') || str_starts_with((string) $k, 'SYSTEM_AI_')) {
        echo $k . '=' . $v . PHP_EOL;
    }
}

foreach ([
    'SYSTEM_CAP_ARTICLE_CONTENT_GENERATE',
    'SYSTEM_CAP_CONTENT_ARTICLE_GENERATE',
    'SYSTEM_AI_MODE',
] as $k) {
    $v = env($k);
    if ($v !== null && $v !== '') {
        echo "env:{$k}={$v}" . PHP_EOL;
    }
}

echo 'PHP_SAPI=' . PHP_SAPI . PHP_EOL;
echo 'NOW=' . now()->toDateTimeString() . PHP_EOL;
