<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;

echo "=== PHASE4 ONE LIVE RERUN FROM WRITING ===" . PHP_EOL;
echo 'before_hash=' . hash('sha256', trim((string) DB::connection('omi_seo_ai')->table('articles')->where('id', 8553)->value('body'))) . PHP_EOL;
echo 'before_updated_at=' . DB::connection('omi_seo_ai')->table('articles')->where('id', 8553)->value('updated_at') . PHP_EOL;
echo 'task_status=' . DB::connection('omi_seo_ai')->table('seo_project_tasks')->where('id', 8799)->value('status') . PHP_EOL;

$bus = app(ContentProjectCommandBus::class);
$command = new RerunProjectItemStepCommand(
    projectRef: 900,
    itemRefs: [8799],
    fromStep: ContentProjectRerunFromStep::Article,
    includeDownstream: false,
    sourceArticleId: 8553,
    mode: 'full',
    syncExecution: false,
    settings: [],
);

$result = $bus->dispatch($command, ActorContext::system('writing-8553-forensic-live'));

echo 'success=' . ($result->success ? 'true' : 'false') . PHP_EOL;
echo 'code=' . ($result->code ?? '') . PHP_EOL;
echo 'message=' . ($result->message ?? '') . PHP_EOL;
echo 'metadata=' . json_encode($result->metadata ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'affected=' . json_encode($result->affectedItemIds ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;

$runId = null;
if (isset($result->metadata['execution_ref'])) {
    // parse cp_exec_xxx or similar
    echo 'execution_ref=' . $result->metadata['execution_ref'] . PHP_EOL;
}
$latest = DB::connection('omi_seo_ai')->table('seo_project_runs')->where('project_id', 900)->orderByDesc('id')->first();
if ($latest) {
    echo 'latest_run=' . json_encode([
        'id' => $latest->id,
        'status' => $latest->status,
        'mode' => $latest->mode,
        'created_at' => $latest->created_at,
        'settings' => is_string($latest->settings) ? json_decode($latest->settings, true) : $latest->settings,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $runId = (int) $latest->id;
}

if ($runId) {
    $ri = DB::connection('omi_seo_ai')->table('seo_project_run_items')->where('run_id', $runId)->where('task_id', 8799)->orderByDesc('id')->first();
    if ($ri) {
        echo 'run_item=' . json_encode($ri, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}

$jobs = DB::table('jobs')->where('queue', 'seo-content-run')->orderByDesc('id')->limit(5)->get();
echo 'seo_content_run_jobs=' . DB::table('jobs')->where('queue', 'seo-content-run')->count() . PHP_EOL;
foreach ($jobs as $j) {
    $payload = json_decode($j->payload, true);
    echo 'JOB\t' . json_encode([
        'id' => $j->id,
        'queue' => $j->queue,
        'displayName' => $payload['displayName'] ?? null,
        'job_id' => $payload['uuid'] ?? ($payload['id'] ?? null),
        'created_at' => $j->created_at,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "DONE_PHASE4_DISPATCH" . PHP_EOL;
