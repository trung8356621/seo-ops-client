<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('seo_ai_models')->where('status', 'active')->limit(500)->get(['id', 'raw_model_name', 'capabilities']);
$with = 0;
$without = 0;
$samplesWith = [];
$samplesWithout = [];
foreach ($rows as $r) {
    $caps = json_decode((string) $r->capabilities, true) ?: [];
    $meta = is_array($caps['provider_metadata'] ?? null) ? $caps['provider_metadata'] : [];
    $max = $caps['max_output_tokens'] ?? $meta['max_completion_tokens'] ?? null;
    if ($max !== null && (int) $max > 0) {
        $with++;
        if (count($samplesWith) < 8) {
            $samplesWith[] = [$r->raw_model_name, (int) $max];
        }
    } else {
        $without++;
        if (count($samplesWithout) < 8 && str_contains((string) $r->raw_model_name, ':free')) {
            $samplesWithout[] = $r->raw_model_name;
        }
    }
}
echo "with_max={$with} without_max={$without}\n";
echo 'samples_with='.json_encode($samplesWith, JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'samples_without_free='.json_encode($samplesWithout, JSON_UNESCAPED_UNICODE).PHP_EOL;
