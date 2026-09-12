<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use App\Models\WpOption;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);

$opt = WpOption::query()->where('option_name', SeoCreateArticleSettingsService::OPTION_KEY)->first();
echo 'option_exists='.($opt ? 'yes' : 'no')."\n";
if ($opt) {
    $val = $opt->option_value;
    echo 'raw_len='.mb_strlen((string) $val)."\n";
    $decoded = is_string($val) ? json_decode($val, true) : $val;
    if (! is_array($decoded) && is_string($val)) {
        $decoded = @unserialize($val);
    }
    echo 'decoded_type='.gettype($decoded)."\n";
    if (is_array($decoded)) {
        echo 'publish_article_task_id='.var_export($decoded['publish_article_task_id'] ?? null, true)."\n";
        echo 'keys='.implode(',', array_keys($decoded))."\n";
    }
}

$task = SeoTask::query()->find(1);
echo 'task1 active='.($task?->is_active ? '1' : '0').' name='.$task?->name."\n";

$settings = app(SeoCreateArticleSettingsService::class);
echo 'before='.var_export($settings->getPublishArticleTaskId(), true)."\n";

// Restore binding used by prior E2E (run 189) — env was cleared after MySQL restart / empty option.
if ($settings->getPublishArticleTaskId() === null && $task?->is_active) {
    $all = $settings->getSettings();
    $all[SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE] = 1;
    // Prefer public save API if available
    if (method_exists($settings, 'updateSettings')) {
        $settings->updateSettings($all);
    } elseif (method_exists($settings, 'save')) {
        $settings->save($all);
    } else {
        WpOption::query()->updateOrCreate(
            ['option_name' => SeoCreateArticleSettingsService::OPTION_KEY],
            ['option_value' => json_encode($all, JSON_UNESCAPED_UNICODE)],
        );
    }
    // clear any static cache by new instance
    $settings = app(SeoCreateArticleSettingsService::class);
    echo 'after='.var_export($settings->getPublishArticleTaskId(), true)."\n";
}
