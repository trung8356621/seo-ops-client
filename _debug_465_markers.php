<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Auth;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
Auth::loginUsingId(2);
$r = SeoDatabaseConnection::query()->where('is_active', true)->first();
if ($r) {
    app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($r);
}
$p = SeoPrompt::find(5);
$c = '';
foreach ($p->resolvedParts() as $part) {
    $c .= (string) $part->content."\n";
}
foreach (['DYNAMIC WORD ALLOCATION', 'target 1000 words', '80% of 1000', 'STRICT LENGTH', '2000 words', '{{article_length}}'] as $m) {
    echo $m.': '.(str_contains($c, $m) ? 'YES' : 'no').PHP_EOL;
}
