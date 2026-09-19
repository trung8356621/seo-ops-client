<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http;

$base = rtrim((string) env('SYSTEM_API_BASE_URL'), '/');
$token = (string) env('SYSTEM_API_TOKEN');
Http::globalOptions(['timeout' => 45, 'connect_timeout' => 5]);

$t0 = microtime(true);
$r = Http::withToken($token)->acceptJson()->get($base.'/api/system/v1/ai/executions/probe-8553-no-gen');
echo 'AUTH_GET status='.$r->status().' secs='.round(microtime(true)-$t0, 2).' body='.substr($r->body(), 0, 250).PHP_EOL;

// Spawn second cgi hint: remote needs concurrent capacity. For now just confirm auth path.
echo 'cgi_note=single_cgi_ok_if_auth_get_returns_404'.PHP_EOL;
echo 'DONE'.PHP_EOL;
