<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Illuminate\Support\Facades\DB;
$run=DB::connection('omi_seo_ai')->table('seo_site_sync_runs')->where('id',38)->first();
echo "status={$run->status} baseline_meta=";
$meta=json_decode($run->meta,true);
echo json_encode(['v3'=>$meta['v3_baseline_completed_at']??null,'gen'=>$meta['v3_baseline_generation']??null])."\n";
$site=App\Models\Site::find(2);
echo "site_baseline=".json_encode(['at'=>$site->getMeta('seo_site_sync_v3_baseline_completed_at'),'gen'=>$site->getMeta('seo_site_sync_v3_baseline_generation')])."\n";
foreach([11284,11285] as $id){
  $wal=DB::connection('omi_seo_ai')->table('wordpress_article_links')->where('site_id',2)->where('wp_post_id',$id)->first();
  echo "wp $id gen=".($wal->last_seen_sync_generation??'null')." art=".($wal->article_id??'null')."\n";
}
$rep=json_decode(file_get_contents('_v3_acceptance_site2_report.json'),true);
echo "delete_http=".json_encode($rep['delete_http']??null)."\n";
echo "blocker=".json_encode($rep['blocker_delete']??null)." baseline_ok=".json_encode($rep['baseline_ok']??null)." urlkw=".json_encode($rep['url_keyword_count']??null)."\n";
$url=DB::connection('omi_seo_ai')->table('keywords')->where('phrase','like','%maybalotuixachgiare%')->count();
echo "url_kw_now=$url\n";
$ui=app(Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter::class);
$built=$ui->buildForSite($site);
echo "ui status=".($built['status']??'?')." running=".json_encode($built['running']??null)." cancellable=".json_encode($built['cancellable']??null)." msg=".($built['message']??'')."\n";
