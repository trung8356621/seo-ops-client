<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use App\Models\Site; use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
$site=Site::find(2); $client=app(WordPressSiteSyncV3Client::class);
$d=$client->discover($site); $discover=$d['discover'];
$snapshotAt=(string)($discover['snapshot_at']??'');
$bounds=$discover['snapshot_bounds'];
$wpIds=[]; $wpTypes=[]; $cursor=null; $pages=0;
while($pages<80){
  $pages++;
  $body=['schema'=>'site_sync.v3','resource'=>'content','mode'=>'full','limit'=>100,'cursor'=>$cursor,'snapshot_at'=>$snapshotAt,'snapshot_bounds'=>$bounds,'sync_generation'=>999];
  $r=$client->records($site,$body);
  if(!($r['success']??false)){ echo "FAIL ".$pages." ".($r['message']??'')."\n"; break; }
  $rec=$r['records']??[]; $items=$rec['items']??[];
  foreach($items as $it){ if(!is_array($it)) continue; $id=(int)($it['wp_id']??0); if($id<=0) continue; $t=(string)($it['type']??$it['content_type']??''); $wpIds[$id]=true; $wpTypes[$id]=$t; }
  $hasMore=(bool)($rec['has_more']??false); $cursor=$rec['cursor']??$rec['next_cursor']??null;
  if(!$hasMore||$cursor===null) break;
}
echo "WP content ids=".count($wpIds)." pages=$pages snap=$snapshotAt\n";
$db=DB::connection('omi_seo_ai');
$rows=$db->table('wordpress_article_links as wal')->join('articles as a','a.id','=','wal.article_id')
  ->leftJoin('article_meta as am', function($j){ $j->on('am.article_id','=','a.id')->where('am.meta_key','=','content_type'); })
  ->where('wal.site_id',2)->where('wal.wp_post_id','>',0)->whereNull('a.deleted_at')
  ->get(['wal.wp_post_id','am.meta_value as content_type','a.type as article_type']);
$local=[]; $localType=[];
foreach($rows as $r){ $id=(int)$r->wp_post_id; $local[$id]=true; $ct=(string)($r->content_type??''); if($ct==='') $ct=(string)($r->article_type??'other'); $localType[$id]=$ct; }
$missing=[]; $extra=[]; $mismatch=[];
foreach($wpIds as $id=>$_){ if(!isset($local[$id])) $missing[]=$id; else { $wt=$wpTypes[$id]??''; $lt=$localType[$id]??''; if(in_array($wt,['post','page','product'],true) && in_array($lt,['post','page','product'],true) && $wt!==$lt) $mismatch[]=compact('id','wt','lt'); }}
foreach($local as $id=>$_){ if(!isset($wpIds[$id])) $extra[]=$id; }
sort($missing); sort($extra);
echo "local=".count($local)." missing=".count($missing)." extra=".count($extra)." mismatch=".count($mismatch)."\n";
echo "missing=".json_encode($missing)."\n";
echo "extra_sample=".json_encode(array_slice($extra,0,40))."\n";
echo "mismatch_sample=".json_encode(array_slice($mismatch,0,20))."\n";
// type tallies
$wpt=['post'=>0,'page'=>0,'product'=>0,'other'=>0]; foreach($wpTypes as $t){ $k=in_array($t,['post','page','product'],true)?$t:'other'; $wpt[$k]++; }
$lt=['post'=>0,'page'=>0,'product'=>0,'other'=>0]; foreach($localType as $t){ $k=in_array($t,['post','page','product'],true)?$t:'other'; $lt[$k]++; }
echo "wp_types=".json_encode($wpt)." local_types=".json_encode($lt)."\n";
