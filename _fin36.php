<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Illuminate\Support\Facades\DB;
$db=DB::connection('omi_seo_ai');
$run=$db->table('seo_site_sync_runs')->where('id',36)->first();
echo "status={$run->status} step={$run->current_step} finished={$run->finished_at}\n";
$meta=json_decode($run->meta,true);
echo "verify=".json_encode($meta['verify']??[],JSON_UNESCAPED_UNICODE)."\n";
echo "baseline meta=".json_encode(['v3_at'=>$meta['v3_baseline_completed_at']??null,'gen'=>$meta['v3_baseline_generation']??null])."\n";
$site=App\Models\Site::find(2);
echo "site baseline=".json_encode(['at'=>$site->getMeta('seo_site_sync_v3_baseline_completed_at'),'gen'=>$site->getMeta('seo_site_sync_v3_baseline_generation')])."\n";

// delete candidate
$wal=$db->table('wordpress_article_links')->where('site_id',2)->where('wp_post_id',11279)->first();
$art=$wal?$db->table('articles')->where('id',$wal->article_id)->first():null;
echo "delete11279 wal=".json_encode($wal)." art_del=".($art->deleted_at??'n/a')." title=".($art->title??'')."\n";

// receipts catchup
foreach($db->table('seo_site_sync_v3_receipts')->where('run_id',36)->where('resource','like','catch_up%')->get() as $r){
  echo "receipt job={$r->processing_job_number} res={$r->resource} items={$r->item_count} up={$r->upsert_count} del={$r->delete_count} after={$r->cursor_after}\n";
}

// finish phase complete
$orch=app(Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator::class);
$orch->handle(36);
$run=$db->table('seo_site_sync_runs')->where('id',36)->fresh() ?? $db->table('seo_site_sync_runs')->where('id',36)->first();
echo "after tick status={$run->status} step={$run->current_step} finished={$run->finished_at}\n";
