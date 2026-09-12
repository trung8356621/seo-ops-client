<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);

$settings = app(SeoCreateArticleSettingsService::class);
$all = $settings->getSettings();
echo 'publish='.var_export($all['publish_article_task_id'] ?? null, true)."\n";
$bindings = $all['prompt_hook_bindings'] ?? [];
echo 'bindings='.json_encode($bindings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";

// Ensure content.generate bound to prompt 5 if missing
if (! isset($bindings['article.content.generate']) || (int) ($bindings['article.content.generate']['prompt_id'] ?? 0) <= 0) {
    $bindings['article.content.generate'] = ['prompt_id' => 5, 'enabled' => true];
    $settings->saveSettings([
        'prompt_hook_bindings' => $bindings,
        'publish_article_task_id' => 1,
    ]);
    echo "restored article.content.generate -> 5\n";
}
$all = app(SeoCreateArticleSettingsService::class)->getSettings();
echo 'content_binding='.json_encode($all['prompt_hook_bindings']['article.content.generate'] ?? null)."\n";
echo 'structure='.json_encode($all['prompt_hook_bindings']['article.outline.structure.generate'] ?? null)."\n";
echo 'vocab='.json_encode($all['prompt_hook_bindings']['article.vocabulary.generate'] ?? null)."\n";
