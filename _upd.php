<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
$site=App\Models\Site::find(2);
$base='https://maybalotuixachgiare.com';
$read=trim((string)$site->getMeta('seo_read_token'));
$write=trim((string)$site->getMeta('seo_migration_token'));
$caps=Illuminate\Support\Facades\Http::timeout(30)->acceptJson()->withToken($read)->get($base.'/wp-json/omi-seo-ai/v1/capabilities');
echo "caps=".$caps->status()."\n";
$j=$caps->json();
echo json_encode(['bridge'=>$j['bridge_version']??null,'plugin_update'=>$j['capabilities']['plugin_update']??null,'manual_update'=>$j['capabilities']['manual_update']??null,'github'=>$j['capabilities']['github_release_update']??null], JSON_PRETTY_PRINT)."\n";
foreach(['/wp-json/omi-seo-ai/v1/plugin/github-check?force_refresh=1','/wp-json/omi-seo-ai/v1/update/check?force_refresh=1','/wp-json/omi-seo-ai/v1/bridge/update-check?force_refresh=1'] as $path){
  $r=Illuminate\Support\Facades\Http::timeout(90)->acceptJson()->withToken($write)->get($base.$path);
  if($r->status()===404){ $r=Illuminate\Support\Facades\Http::timeout(90)->acceptJson()->withToken($read)->get($base.$path); }
  echo "$path => ".$r->status()." ".substr(preg_replace('/\s+/',' ',$r->body()),0,250)."\n";
}
