<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$jobs = DB::table('jobs')->where('queue', 'seo-content-run')->orderBy('id')->get();
foreach ($jobs as $job) {
    echo json_encode([
        'id' => $job->id,
        'queue' => $job->queue,
        'attempts' => $job->attempts,
        'reserved_at' => $job->reserved_at,
        'available_at' => $job->available_at,
        'created_at' => $job->created_at,
        'payload_preview' => substr((string) $job->payload, 0, 200),
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
}

// Release reserved jobs so a fresh worker can pick them up.
$n = DB::table('jobs')->where('queue', 'seo-content-run')->whereNotNull('reserved_at')->update([
    'reserved_at' => null,
    'attempts' => 0,
]);
echo "released={$n}\n";
echo 'pending_now='.DB::table('jobs')->where('queue', 'seo-content-run')->count().PHP_EOL;
