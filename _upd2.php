<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
$site=App\Models\Site::find(2);
$base='https://maybalotuixachgiare.com';
$write=trim((string)$site->getMeta('seo_migration_token'));
$read=trim((string)$site->getMeta('seo_read_token'));
$r=Illuminate\Support\Facades\Http::timeout(120)->acceptJson()->withToken($write)
  ->get($base.'/wp-json/omi-seo-ai/v1/plugin-update/check',['force_refresh'=>1]);
echo "check=".$r->status()." ".substr($r->body(),0,800)."\n";
$j=$r->json();
if(($j['update_available']??false) || (($j['latest_version']??'')!=='' && version_compare((string)($j['latest_version']??'0'),'1.0.85','>'))){
  $inst=Illuminate\Support\Facades\Http::timeout(180)->acceptJson()->withToken($write)
    ->post($base.'/wp-json/omi-seo-ai/v1/plugin-update/install',[]);
  echo "install=".$inst->status()." ".substr($inst->body(),0,800)."\n";
}
$d=app(Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client::class)->discover($site);
echo "bridge_after=".(($d['discover']['profile']['bridge_version']??'?'))."\n";
