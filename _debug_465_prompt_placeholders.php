<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
if ($rec) {
    app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
}

$prompt = SeoPrompt::query()->findOrFail(5);
$placeholders = [];
$sections = [];
foreach ($prompt->resolvedParts() as $part) {
    if ((string) $part->role === 'global_constraints') {
        continue;
    }
    $c = (string) $part->content;
    preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $c, $m);
    foreach ($m[1] as $ph) {
        $placeholders[$ph] = ($placeholders[$ph] ?? 0) + 1;
    }
    // Extract ## section titles inside content
    preg_match_all('/^##\s+(.+)$/mu', $c, $sm);
    foreach ($sm[1] as $t) {
        $sections[] = $t;
    }
}

$payload = [
    'sessionId' => 'd04245',
    'hypothesisId' => 'H7',
    'message' => 'prompt5_placeholders_and_sections',
    'data' => [
        'placeholders' => $placeholders,
        'internal_sections' => $sections,
        'hook_key' => $prompt->hook_key,
        'name' => $prompt->name,
    ],
    'timestamp' => (int) round(microtime(true) * 1000),
];
file_put_contents(__DIR__.'/debug-d04245.log', json_encode($payload, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
echo json_encode($payload['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
