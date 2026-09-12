<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use App\Models\WpOption;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);

$settings = app(SeoCreateArticleSettingsService::class);
$settings->saveSettings([
    SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE => 1,
]);

echo 'after_save='.var_export($settings->getPublishArticleTaskId(), true)."\n";
$opt = WpOption::query()->where('option_name', SeoCreateArticleSettingsService::OPTION_KEY)->first();
echo 'option_exists='.($opt ? 'yes' : 'no').' val_preview='.mb_substr((string) ($opt?->option_value ?? ''), 0, 200)."\n";
