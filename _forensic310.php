<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$pr = DB::connection('omi_seo_ai')->table('prompt_results')->where('id', 2108)->first();
$a = (array)$pr;
foreach (['input_snapshot','output_text'] as $h) {
    if (isset($a[$h]) && is_string($a[$h])) {
        $a[$h.'_len'] = strlen($a[$h]);
        $a[$h.'_hash'] = hash('sha256', trim($a[$h]));
        $a[$h] = substr($a[$h], 0, 120).'...';
    }
}
echo "PR2108=" . json_encode($a, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;

$atts = DB::connection('omi_seo_ai')->table('prompt_result_routing_attempts')->where('prompt_result_id', 2108)->orderBy('id')->get();
foreach ($atts as $at) {
    $r=(array)$at;
    foreach ($r as $k=>$v) if (is_string($v)&&strlen($v)>150) $r[$k]=substr($v,0,100).'...';
    echo "ATTEMPT\t".json_encode($r, JSON_UNESCAPED_UNICODE).PHP_EOL;
}

$ri = DB::connection('omi_seo_ai')->table('seo_project_run_items')->where('id', 825)->first();
echo "RI825 status={$ri->status} msg=".substr((string)$ri->message,0,200).PHP_EOL;
echo "body_hash_now=".hash('sha256', trim((string)DB::connection('omi_seo_ai')->table('articles')->where('id',8553)->value('body'))).PHP_EOL;

// remote timeout config
$ref = new ReflectionClass(\App\System\Ai\Transport\RemoteHttpAiTransport::class);
$ctor = $ref->getConstructor();
echo "RemoteHttp ctor params: ";
foreach ($ctor->getParameters() as $p) echo $p->getName().' ';
echo PHP_EOL;
echo "system.http config=".json_encode(config('system.http'), JSON_UNESCAPED_UNICODE).PHP_EOL;
