<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use App\Models\WpOption;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);

echo 'default_db='.DB::connection()->getDatabaseName()."\n";
echo 'omi_seo='.DB::connection('omi_seo_ai')->getDatabaseName()."\n";

$settings = app(SeoCreateArticleSettingsService::class);
$settings->savePromptHookBindings([
    'article.outline.structure.generate' => 22,
    'article.vocabulary.generate' => 23,
    'article.content.generate' => 5,
    'article.outline.generate' => 22, // legacy if needed
]);
$settings->saveSettings([
    SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE => 1,
]);

echo 'publish='.var_export($settings->getPublishArticleTaskId(), true)."\n";
echo 'bindings='.json_encode($settings->getPromptHookBindings(), JSON_UNESCAPED_UNICODE)."\n";
echo 'structure_id='.var_export($settings->getBoundPromptId('article.outline.structure.generate'), true)."\n";

$opt = WpOption::query()->where('option_name', SeoCreateArticleSettingsService::OPTION_KEY)->first();
echo 'wp_option_conn_model='.(new WpOption)->getConnectionName()."\n";
echo 'raw='.mb_substr((string) ($opt?->option_value ?? ''), 0, 500)."\n";
