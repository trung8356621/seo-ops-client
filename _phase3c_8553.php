<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$base = rtrim((string) env('SYSTEM_API_BASE_URL'), '/');
$token = (string) env('SYSTEM_API_TOKEN');

Http::globalOptions(['timeout' => 8, 'connect_timeout' => 3]);

try {
    $r = Http::withToken($token)->acceptJson()->get($base.'/api/system/v1/ai/executions/probe-8553-no-gen');
    echo "HTTP_GET_AUTH status={$r->status()} body=" . substr($r->body(), 0, 250) . PHP_EOL;
} catch (Throwable $e) {
    echo "HTTP_GET_AUTH EX=" . $e->getMessage() . PHP_EOL;
}

try {
    $r = Http::acceptJson()->get($base.'/api/system/v1/ai/executions/probe-8553-no-gen');
    echo "HTTP_GET_NOAUTH status={$r->status()} body=" . substr($r->body(), 0, 250) . PHP_EOL;
} catch (Throwable $e) {
    echo "HTTP_GET_NOAUTH EX=" . $e->getMessage() . PHP_EOL;
}

try {
    $r = Http::withToken($token)->acceptJson()->asJson()->post($base.'/api/system/v1/ai/executions', [
        'capability' => 'article.content.generate',
        // deliberately incomplete — must NOT generate
    ]);
    echo "HTTP_POST_INCOMPLETE status={$r->status()} body=" . substr($r->body(), 0, 400) . PHP_EOL;
} catch (Throwable $e) {
    echo "HTTP_POST_INCOMPLETE EX=" . $e->getMessage() . PHP_EOL;
}

// Also invoke controller in-process (no HTTP) to prove handler exists
try {
    $controller = app(\App\System\Ai\Api\AiExecutionController::class);
    $req = \Illuminate\Http\Request::create('/api/system/v1/ai/executions/probe-8553-no-gen', 'GET');
    $resp = $controller->show($req, 'probe-8553-no-gen');
    echo "INPROCESS_SHOW status=" . $resp->getStatusCode() . " body=" . substr((string)$resp->getContent(), 0, 250) . PHP_EOL;
} catch (Throwable $e) {
    echo "INPROCESS_SHOW EX=" . $e->getMessage() . PHP_EOL;
}

echo "DONE" . PHP_EOL;
