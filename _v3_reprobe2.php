<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;

$candidates = [2, 4, 5, 3]; // exclude 6,7

foreach ($candidates as $id) {
    $site = Site::query()->find($id);
    if ($site === null) {
        continue;
    }
    $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));
    $domain = trim((string) $site->domain);
    $base = preg_match('#^https?://#i', $domain) ? rtrim($domain, '/') : 'https://'.ltrim($domain, '/');
    echo "==== {$id} {$base} ====\n";

    try {
        $res = Http::timeout(25)->acceptJson()->withToken($token)->get($base.'/wp-json/omi-seo-ai/v1/capabilities');
        $json = $res->json();
        $bridge = is_array($json) ? (string) ($json['manifest']['bridge_version'] ?? $json['bridge_version'] ?? '') : '';
        $caps = is_array($json) ? ($json['manifest']['capabilities'] ?? $json['capabilities'] ?? []) : [];
        $hasV3 = is_array($caps) && isset($caps['site_sync_v3']);
        echo "caps HTTP {$res->status()} bridge={$bridge} site_sync_v3=".($hasV3 ? 'yes' : 'no')."\n";
    } catch (Throwable $e) {
        echo 'caps ERR '.$e->getMessage()."\n";
    }

    $d = app(WordPressSiteSyncV3Client::class)->discover($site);
    echo 'discover='.(($d['success'] ?? false) ? 'ok' : 'fail').' '.($d['message'] ?? '')."\n";
    if (($d['success'] ?? false) && is_array($d['discover'] ?? null)) {
        $disc = $d['discover'];
        $bounds = is_array($disc['snapshot_bounds'] ?? null) ? $disc['snapshot_bounds'] : [];
        echo 'total='.($disc['total'] ?? '').' content_max='.($bounds['content_max_id'] ?? '?').' term_max='.($bounds['term_max_id'] ?? '?')."\n";
        echo 'by_type='.json_encode($disc['by_content_type'] ?? null)."\n";
        echo 'snapshot_at='.($disc['snapshot_at'] ?? '')."\n";
    }

    // quick route presence
    try {
        $ns = Http::timeout(20)->acceptJson()->withToken($token)->get($base.'/wp-json/omi-seo-ai/v1');
        $routes = is_array($ns->json()['routes'] ?? null) ? array_keys($ns->json()['routes']) : [];
        $v3 = array_values(array_filter($routes, static fn ($r) => str_contains((string) $r, 'sync/v3')));
        echo 'v3_routes='.json_encode($v3)."\n";
    } catch (Throwable $e) {
        echo 'ns ERR '.$e->getMessage()."\n";
    }
    echo "\n";
}
