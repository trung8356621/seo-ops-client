<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
if ($rec) {
    app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
}

$task = SeoProjectTask::query()->findOrFail(465);
echo "task status={$task->status} article={$task->article_id} site={$task->site_id}\n";
echo "task attrs=".json_encode(array_intersect_key($task->getAttributes(), array_flip([
    'id', 'project_id', 'status', 'article_id', 'site_id', 'workflow_id', 'task_template_id', 'prompt_workflow_id',
])), JSON_UNESCAPED_UNICODE)."\n";

foreach ([189, 190] as $rid) {
    $run = SeoProjectRun::query()->find($rid);
    $items = SeoProjectRunItem::query()->where('run_id', $rid)->get();
    echo "run $rid status={$run?->status} s={$run?->succeeded} f={$run?->failed}\n";
    foreach ($items as $i) {
        echo "  item {$i->id} status={$i->status} err=".mb_substr((string) ($i->error_message ?? $i->message ?? ''), 0, 300)."\n";
        $out = is_array($i->output) ? $i->output : [];
        echo "  output_keys=".implode(',', array_keys($out))."\n";
    }
}

// Compare site workflow settings
$siteId = (int) $task->site_id;
echo "site_id=$siteId\n";
