<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Illuminate\Support\Facades\DB;

$task = DB::connection('omi_seo_ai')->table('seo_tasks')->where('id', 1)->first();
echo 'task1=' . json_encode($task ? ['id'=>$task->id,'name'=>$task->name,'is_active'=>$task->is_active] : null, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$settings = app(SeoCreateArticleSettingsService::class);
echo 'before=' . var_export($settings->getPublishArticleTaskId(), true) . PHP_EOL;
$settings->saveWorkflowsOperatorSettings([
    SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE => 1,
]);
echo 'after=' . var_export($settings->getPublishArticleTaskId(), true) . PHP_EOL;
echo "DONE_RESTORE" . PHP_EOL;
