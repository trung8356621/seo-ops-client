<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AiModel;

$first = AiModel::first();
if ($first) {
    print_r(array_keys($first->toArray()));
    echo "\nSample record:\n";
    print_r($first->toArray());
} else {
    echo "No models found!\n";
}
