<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$p = DB::connection('omi_seo_ai')->table('prompts')->where('id', 5)->first();
echo 'PROMPT5=' . json_encode($p ? [
    'id' => $p->id,
    'user_id' => $p->user_id ?? null,
    'hook_key' => $p->hook_key ?? null,
    'name' => $p->name ?? null,
    'ai_connection_id' => $p->ai_connection_id ?? null,
    'status' => $p->status ?? null,
] : null, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$hooks = DB::connection('omi_seo_ai')->table('prompts')
    ->where('hook_key', 'article.content.generate')
    ->get(['id','user_id','name','hook_key','ai_connection_id','status']);
foreach ($hooks as $h) {
    echo 'HOOK_PROMPT=' . json_encode($h, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

foreach ([1,2,8] as $id) {
    $u = DB::table('users')->where('id', $id)->first(['id','name','email']);
    echo 'USER=' . json_encode($u, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

// How many routing targets per user for text.longform
foreach ([1,2,8] as $uid) {
    $n = DB::table('ai_routing_targets')->where('user_id', $uid)->where('profile', 'text.longform')->count();
    echo "targets_user_{$uid}_text.longform={$n}" . PHP_EOL;
    $areas = DB::table('ai_routing_targets')->where('user_id', $uid)->select('profile', DB::raw('count(*) as c'))->groupBy('profile')->get();
    echo "profiles_user_{$uid}=" . json_encode($areas, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

// System AI remote probe (no generation)
$base = rtrim((string) env('SYSTEM_API_BASE_URL'), '/');
$tokenSet = env('SYSTEM_API_TOKEN') ? 'yes' : 'no';
echo "SYSTEM_API_BASE_URL={$base}" . PHP_EOL;
echo "SYSTEM_API_TOKEN_SET={$tokenSet}" . PHP_EOL;
echo "SYSTEM_CAP_ARTICLE_CONTENT_GENERATE=" . env('SYSTEM_CAP_ARTICLE_CONTENT_GENERATE') . PHP_EOL;

// Find remote transport endpoint path from code/config
$paths = [
    '/api/system/ai/execute',
    '/api/system-ai/execute',
    '/api/internal/system-ai/execute',
    '/api/system/capabilities/article.content.generate',
];
foreach ($paths as $path) {
    $url = $base . $path;
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST => 'OPTIONS',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        echo "HTTP_OPTIONS\t{$path}\tcode={$code}\terr={$err}\tbody_len=" . strlen((string)$body) . PHP_EOL;
    } catch (Throwable $e) {
        echo "HTTP_OPTIONS\t{$path}\tEX={$e->getMessage()}" . PHP_EOL;
    }
}

// GET homepage / health
foreach (['/', '/up', '/api/health'] as $path) {
    $url = $base . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    echo "HTTP_GET\t{$path}\tcode={$code}\terr={$err}\tbody_len=" . strlen((string)$body) . PHP_EOL;
}

echo "DONE" . PHP_EOL;
