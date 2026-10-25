<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$inst = App\Models\H1Installation::query()->orderBy('id')->first();
$sites = App\Models\Site::query()->count();
$articles = null;
try {
    $articles = Illuminate\Support\Facades\DB::connection('omi_seo_ai')->table('articles')->count();
} catch (Throwable $e) {
    $articles = 'n/a: ' . $e->getMessage();
}

echo json_encode([
    'installation_id' => $inst?->installation_id,
    'revoked_at' => $inst?->verified_snapshot['revoked_at'] ?? null,
    'lifecycle_reason' => $inst?->verified_snapshot['lifecycle_reason'] ?? null,
    'sites_count' => $sites,
    'articles_count' => $articles,
    'entitlement_revision' => $inst?->entitlement_revision,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
