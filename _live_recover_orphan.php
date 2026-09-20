<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use Illuminate\Support\Facades\DB;

$conn = 'omi_seo_ai';

DB::connection($conn)->table('seo_project_run_items')->where('id', 903)->update([
    'status' => 'failed',
    'error_code' => 'CONTENT_PROJECT_EXTERNAL_WORKFLOW_FAILED',
    'error_message' => 'Orphan processing row recovered after worker interrupt (pre-budget-fix run).',
    'message' => 'Orphan processing row recovered after worker interrupt (pre-budget-fix run).',
    'finished_at' => now(),
    'updated_at' => now(),
]);

DB::connection($conn)->table('seo_project_runs')->where('id', 330)->update([
    'status' => 'completed',
    'failed' => 1,
    'succeeded' => 0,
    'finished_at' => now(),
    'updated_at' => now(),
]);

DB::connection($conn)->table('seo_project_tasks')->where('id', 8856)->update([
    'status' => 'failed',
    'updated_at' => now(),
]);

echo "recovered orphan 903 / run 330 / task 8856\n";
echo 'task='.json_encode(DB::connection($conn)->table('seo_project_tasks')->where('id', 8856)->first(['id', 'status'])).PHP_EOL;
echo 'run='.json_encode(DB::connection($conn)->table('seo_project_runs')->where('id', 330)->first(['id', 'status', 'failed'])).PHP_EOL;
echo 'item='.json_encode(DB::connection($conn)->table('seo_project_run_items')->where('id', 903)->first(['id', 'status'])).PHP_EOL;
