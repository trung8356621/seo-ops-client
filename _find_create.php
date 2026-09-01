<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Illuminate\Support\Facades\DB;
$db=DB::connection('omi_seo_ai');
// recent articles
$arts=$db->table('articles')->where('site_id',2)->orderByDesc('id')->limit(5)->get(['id','title','created_at','deleted_at']);
echo "recent_arts=".json_encode($arts,JSON_UNESCAPED_UNICODE)."\n";
$links=$db->table('wordpress_article_links')->where('site_id',2)->orderByDesc('id')->limit(5)->get();
echo "recent_links=".json_encode($links,JSON_UNESCAPED_UNICODE)."\n";
// search title
$byTitle=$db->table('articles')->where('site_id',2)->where('title','like','%V3-ACCEPT-CREATE-20260831-020148%')->get(['id','title','deleted_at']);
echo "byTitle=".json_encode($byTitle,JSON_UNESCAPED_UNICODE)."\n";
foreach($byTitle as $a){
  $w=$db->table('wordpress_article_links')->where('article_id',$a->id)->get();
  echo "links_for ".$a->id."=".json_encode($w)."\n";
}
