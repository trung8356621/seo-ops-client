<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Illuminate\Support\Facades\DB;
$db=DB::connection('omi_seo_ai');
foreach([11,8579,11274,11275,11276,11277,11278] as $id){
  $wal=$db->table('wordpress_article_links')->where('site_id',2)->where('wp_post_id',$id)->first();
  $art=$wal?$db->table('articles')->where('id',$wal->article_id)->first():null;
  echo "wp=$id wal=".($wal?$wal->article_id:'null')." art_del=".($art->deleted_at??'n/a')." title=".mb_substr((string)($art->title??''),0,50)."\n";
}
// extras: are they terms?
$extras=[25,26,28,29,30,319,320,1414];
foreach($extras as $id){
  $wal=$db->table('wordpress_article_links')->where('site_id',2)->where('wp_post_id',$id)->first();
  $meta=$wal?$db->table('article_meta')->where('article_id',$wal->article_id)->whereIn('meta_key',['content_type','wp_is_term','wp_taxonomy'])->pluck('meta_value','meta_key'):null;
  $art=$wal?$db->table('articles')->where('id',$wal->article_id)->first(['id','title','type','deleted_at']):null;
  echo "extra $id meta=".json_encode($meta)." art=".json_encode($art,JSON_UNESCAPED_UNICODE)."\n";
}
