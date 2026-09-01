<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
$site=App\Models\Site::find(2);
$base='https://maybalotuixachgiare.com';
$write=trim((string)$site->getMeta('seo_migration_token'));
$read=trim((string)$site->getMeta('seo_read_token'));
// create a disposable post then try delete via wp/v2
$create=Illuminate\Support\Facades\Http::timeout(60)->acceptJson()->withToken($write)
  ->post($base.'/wp-json/omi-seo-ai/v1/posts',['title'=>'V3-TMP-DEL-'.date('His'),'status'=>'publish','content'=>'x','post_type'=>'post']);
echo "create=".$create->status()." ".substr($create->body(),0,300)."\n";
$id=(int)(($create->json()['wp_post_id']??$create->json()['id']??0));
if($id<=0){ exit; }
foreach([
  ['DELETE',"/wp-json/wp/v2/posts/$id?force=true",$write],
  ['DELETE',"/wp-json/omi-seo-ai/v1/posts/$id",$write],
  ['POST',"/wp-json/omi-seo-ai/v1/posts/$id/delete",$write],
] as [$m,$p,$tok]){
  $req=Illuminate\Support\Facades\Http::timeout(60)->acceptJson()->withToken($tok);
  $r=$m==='DELETE'?$req->delete($base.$p):$req->post($base.$p,[]);
  echo "$m $p => ".$r->status()." ".substr(preg_replace('/\s+/',' ',$r->body()),0,200)."\n";
}
