<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

$s = app(SeoCreateArticleSettingsService::class);
$ref = new ReflectionClass($s);
echo 'KEY_PUBLISH=' . $ref->getConstant('KEY_PUBLISH_ARTICLE') . PHP_EOL;
$id = $s->getPublishArticleTaskId();
echo 'publish_task_id=' . var_export($id, true) . PHP_EOL;

$settings = $s->getSettings();
echo 'settings_keys=' . json_encode(array_keys($settings)) . PHP_EOL;
foreach ($settings as $k => $v) {
    if (is_scalar($v) || $v === null) {
        echo "SET\t{$k}=" . json_encode($v) . PHP_EOL;
    }
}

echo "=== seo_tasks ===" . PHP_EOL;
$tasks = DB::connection('omi_seo_ai')->table('seo_tasks')->orderBy('id')->get();
foreach ($tasks as $t) {
    echo 'TASK\t' . json_encode([
        'id' => $t->id,
        'name' => $t->name ?? null,
        'is_active' => $t->is_active ?? null,
        'type' => $t->type ?? null,
        'updated_at' => $t->updated_at ?? null,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

// Where settings stored
foreach (['mysql', 'omi_seo_ai'] as $c) {
    foreach (['seo_create_article_settings', 'settings', 'seo_settings'] as $table) {
        if (Schema::connection($c)->hasTable($table)) {
            echo "TABLE {$c}.{$table}" . PHP_EOL;
            $rows = DB::connection($c)->table($table)->limit(20)->get();
            foreach ($rows as $r) {
                $a = (array)$r;
                foreach ($a as $k => $v) {
                    if (is_string($v) && strlen($v) > 300) $a[$k] = substr($v, 0, 200).'...';
                }
                echo 'ROW\t' . json_encode($a, JSON_UNESCAPED_UNICODE) . PHP_EOL;
            }
        }
    }
}

echo "DONE" . PHP_EOL;
