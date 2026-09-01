<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Illuminate\Support\Facades\DB;
$db=DB::connection('omi_seo_ai');

// Cleanup orphan V3-ACCEPT articles with null wp_post_id
$orphans=$db->table('wordpress_article_links as wal')
  ->join('articles as a','a.id','=','wal.article_id')
  ->where('wal.site_id',2)
  ->where(function($q){ $q->whereNull('wal.wp_post_id')->orWhere('wal.wp_post_id',0); })
  ->where(function($q){ $q->where('a.title','like','V3-ACCEPT-%')->orWhere('a.title','like','V3-CATCHUP-%'); })
  ->get(['a.id','a.title','wal.id as link_id']);
foreach($orphans as $o){
  $db->table('wordpress_article_links')->where('id',$o->link_id)->delete();
  $db->table('articles')->where('id',$o->id)->update(['deleted_at'=>now()]);
  echo "cleaned orphan art={$o->id} {$o->title}\n";
}

// Mark run 35 terminal consistently
$db->table('seo_site_sync_runs')->where('id',35)->update([
  'status'=>'needs_attention',
  'current_step'=>'needs_attention',
  'updated_at'=>now(),
]);
echo "run35 status fixed\n";

// Prove touchSynced fix
$site=App\Models\Site::find(2);
$client=app(Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client::class);
$r=$client->records($site,['schema'=>'site_sync.v3','resource'=>'content','mode'=>'delta','limit'=>10,'cursor'=>null,'since'=>'2026-08-31T02:01:06+00:00','sync_generation'=>99]);
$target=null; foreach(($r['records']['items']??[]) as $it){ if((int)($it['wp_id']??0)===11278) $target=$it; }
if(!$target){ echo "11278 not in delta (may be deleted on WP)\n"; exit(0); }
$run=Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun::find(35);
$counts=app(Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter::class)->importContentChunk($site,$run,[$target]);
$wal=$db->table('wordpress_article_links')->where('site_id',2)->where('wp_post_id',11278)->first();
echo "import=".json_encode($counts)." wal_article=".($wal->article_id??'NULL')." wpid=".($wal->wp_post_id??'NULL')."\n";
