<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Illuminate\Support\Facades\DB;
$db=DB::connection('omi_seo_ai');
$run=$db->table('seo_site_sync_runs')->where('id',35)->first();
$cols=array_keys((array)$run);
echo "COLS=".implode(',', $cols)."\n\n";
echo json_encode($run, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
