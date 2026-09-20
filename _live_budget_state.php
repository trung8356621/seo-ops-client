<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use Illuminate\Support\Facades\DB;

$conn = 'omi_seo_ai';
echo 'pending='.DB::table('jobs')->where('queue', 'seo-content-run')->count().PHP_EOL;
$runs = DB::connection($conn)->table('seo_project_runs')->where('project_id', 904)->orderByDesc('id')->limit(5)->get(['id', 'status', 'succeeded', 'failed', 'updated_at']);
echo 'runs='.json_encode($runs, JSON_UNESCAPED_UNICODE).PHP_EOL;
$task = DB::connection($conn)->table('seo_project_tasks')->where('id', 8856)->first(['id', 'status', 'updated_at']);
echo 'task='.json_encode($task).PHP_EOL;
$items = DB::connection($conn)->table('seo_project_run_items')->where('task_id', 8856)->orderByDesc('id')->limit(5)->get(['id', 'run_id', 'status', 'error_code', 'updated_at']);
echo 'items='.json_encode($items, JSON_UNESCAPED_UNICODE).PHP_EOL;
