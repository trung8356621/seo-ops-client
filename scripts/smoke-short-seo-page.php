<?php

declare(strict_types=1);

use App\Models\SeoDatabaseConnection;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->find(2);
Auth::login($user);
$hash = (string) SeoDatabaseConnection::query()->where('is_active', true)->value('hash_id');

$kernel = $app->make(HttpKernel::class);

foreach ([
    '/seo/content-operations',
    '/seo/'.$hash.'/content-operations',
    '/seo/articles',
    '/seo/'.$hash.'/articles',
] as $path) {
    $request = Request::create('http://seo-ops.test'.$path, 'GET');
    $response = $kernel->handle($request);
    $body = (string) $response->getContent();
    echo $path
        .' status='.$response->getStatusCode()
        .' expired='.(str_contains($body, 'This page has expired') ? 'yes' : 'no')
        .' livewire='.(str_contains(strtolower($body), 'livewire') ? 'yes' : 'no')
        .PHP_EOL;
    $kernel->terminate($request, $response);
}
