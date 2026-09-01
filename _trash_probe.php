<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
$site=App\Models\Site::find(2);
$base='https://maybalotuixachgiare.com';
$write=trim((string)$site->getMeta('seo_migration_token'));
// try trash 11279
$r=Illuminate\Support\Facades\Http::timeout(60)->acceptJson()->withToken($write)
  ->post($base.'/wp-json/omi-seo-ai/v1/posts/11279/editor-sync',['status'=>'trash']);
echo "trash http=".$r->status()." body=".substr($r->body(),0,500)."\n";
// check ledger via delta
$client=app(Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client::class);
$d=$client->records($site,['schema'=>'site_sync.v3','resource'=>'content','mode'=>'delta','limit'=>20,'cursor'=>null,'since'=>'2026-08-31T02:27:00+00:00','sync_generation'=>1]);
foreach(($d['records']['items']??[]) as $it){
  echo "delta wp=".($it['wp_id']??'?')." op=".($it['op']??'upsert')." status=".($it['status']??'')." title=".mb_substr((string)($it['title']??''),0,40)."\n";
}
