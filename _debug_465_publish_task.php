<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
echo 'seo_conn='.json_encode($rec?->only(['id', 'name', 'is_active', 'database']))."\n";
if ($rec) {
    app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
}

// Find settings class
$candidates = [
    \Omnichannel\Addons\Content\Services\SeoContentSettingsService::class,
    \Omnichannel\Addons\Seo\Services\SeoSettingsService::class,
];
foreach ($candidates as $c) {
    echo (class_exists($c) ? 'EXISTS ' : 'MISS ').$c."\n";
}

// Try resolve from CreateArticlesFromTaskService ctor
$ref = new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService::class);
$ctor = $ref->getConstructor();
foreach ($ctor?->getParameters() ?? [] as $p) {
    echo 'dep '.$p->getName().' type='.$p->getType()."\n";
}

$svc = app(\Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService::class);
$settingsProp = $ref->getProperty('settings');
$settingsProp->setAccessible(true);
$settings = $settingsProp->getValue($svc);
echo 'settings_class='.get_class($settings)."\n";
if (method_exists($settings, 'getPublishArticleTaskId')) {
    $id = $settings->getPublishArticleTaskId();
    echo "publish_task_id=".var_export($id, true)."\n";
}
