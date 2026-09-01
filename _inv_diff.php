<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;

$site=Site::find(2);
$client=app(WordPressSiteSyncV3Client::class);
$d=$client->discover($site);
$discover=$d['discover']??[];
$wpTypes=$discover['by_content_type']??[];
$wpTotal=(int)($discover['total']??0);
echo "WP discover success=".json_encode($d['success']??false)." total=$wpTotal by=".json_encode($wpTypes)." bounds=".json_encode($discover['snapshot_bounds']??null)."\n";

$db=DB::connection('omi_seo_ai');
// local wp-backed content ids by type
$rows=$db->table('wordpress_article_links as wal')
  ->join('articles as a','a.id','=','wal.article_id')
  ->leftJoin('article_meta as am', function($j){ $j->on('am.article_id','=','a.id')->where('am.meta_key','=','content_type'); })
  ->where('wal.site_id',2)->where('wal.wp_post_id','>',0)->whereNull('a.deleted_at')
  ->select(['wal.wp_post_id','am.meta_value as content_type','a.id as article_id'])
  ->get();
$local=[]; $byType=['post'=>[],'page'=>[],'product'=>[],'other'=>[]];
foreach($rows as $r){
  $id=(int)$r->wp_post_id; $local[$id]=true;
  $ct=(string)($r->content_type??'');
  if(!isset($byType[$ct])) $ct='other';
  $byType[$ct][$id]=true;
}
echo "Laravel unique=".count($local)." by=".json_encode(array_map('count',$byType))."\n";

// Fetch ALL content wp ids from WP via records full (paged) - expensive; instead request delta since old date with high limit repeatedly?
// Use discover total only + sample via records delta since epoch for membership?
// Better: hit a small debug or use multiple full pages with high max

$wpIds=[];
$cursor=null; $pages=0; $maxId=(int)($discover['snapshot_bounds']['content_max_id']??0);
while($pages<50){
  $pages++;
  $body=['schema'=>'site_sync.v3','resource'=>'content','mode'=>'full','limit'=>100,'cursor'=>$cursor,'snapshot_bounds'=>$discover['snapshot_bounds']??[],'sync_generation'=>999];
  $r=$client->records($site,$body);
  if(!($r['success']??false)){ echo "records fail: ".($r['message']??''); break; }
  $rec=$r['records']??[];
  $items=$rec['items']??$rec['records']??[];
  foreach($items as $it){ if(is_array($it)){ $wpIds[(int)($it['wp_id']??0)]=(string)($it['type']??$it['content_type']??''); } }
  $hasMore=(bool)($rec['has_more']??false);
  $cursor=$rec['cursor']??$rec['next_cursor']??null;
  if(!$hasMore||$cursor===null) break;
}
echo "WP full enumerated=".count($wpIds)." pages=$pages\n";

$missing=[]; $extra=[]; $typeMismatch=[];
foreach($wpIds as $id=>$type){
  if($id<=0) continue;
  if(!isset($local[$id])) $missing[]=['wp_id'=>$id,'type'=>$type];
  else {
    // find local type
    $lt='other';
    foreach(['post','page','product'] as $t){ if(isset($byType[$t][$id])){ $lt=$t; break; } }
    $wt=in_array($type,['post','page','product'],true)?$type:'other';
    if($wt!=='other' && $lt!==$wt && $lt!=='other') $typeMismatch[]=['wp_id'=>$id,'wp'=>$wt,'local'=>$lt];
  }
}
foreach($local as $id=>$_){ if(!isset($wpIds[$id])) $extra[]=$id; }

echo "missing_count=".count($missing)." extra_count=".count($extra)." type_mismatch=".count($typeMismatch)."\n";
echo "missing_wp_ids=".json_encode(array_slice($missing,0,30), JSON_UNESCAPED_UNICODE)."\n";
echo "extra_local_wp_ids=".json_encode(array_slice($extra,0,30))."\n";
echo "type_mismatch_ids=".json_encode(array_slice($typeMismatch,0,20), JSON_UNESCAPED_UNICODE)."\n";

// create candidates from acceptance
foreach([11276,11278,11275,11277] as $cid){
  echo "create_id $cid local=". (isset($local[$cid])?'YES':'NO')." wp=". (isset($wpIds[$cid])?'YES':'NO')."\n";
}
