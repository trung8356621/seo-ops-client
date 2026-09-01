<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Illuminate\Support\Facades\DB;
$db=DB::connection('omi_seo_ai');
$receipts=$db->table('seo_site_sync_v3_receipts')->where('run_id',35)->orderBy('id')->get();
foreach($receipts as $r){
  echo sprintf("#%d job=%s res=%s items=%s up=%s del=%s before=%s after=%s\n",
    $r->id,$r->processing_job_number,$r->resource,$r->item_count,$r->upsert_count,$r->delete_count,
    json_encode($r->cursor_before), json_encode($r->cursor_after));
}
echo "---\n";
// probe WP for missing ids
$site=App\Models\Site::find(2);
$client=app(Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client::class);
$since='2026-08-31T02:01:06+00:00';
$body=['schema'=>'site_sync.v3','resource'=>'content','mode'=>'delta','limit'=>50,'cursor'=>null,'since'=>$since,'sync_generation'=>35,'snapshot_bounds'=>['content_max_id'=>11277,'term_max_id'=>320]];
$r=$client->records($site,$body);
echo "delta success=".json_encode($r['success']??false)." msg=".($r['message']??'')."\n";
$rec=$r['records']??[];
$items=$rec['items']??[];
echo "delta_items=".count($items)." has_more=".json_encode($rec['has_more']??null)." cursor=".json_encode($rec['cursor']??$rec['next_cursor']??null)."\n";
foreach($items as $it){
  if(!is_array($it)) continue;
  echo "  wp_id=".($it['wp_id']??'?')." op=".($it['op']??'upsert')." type=".($it['type']??$it['content_type']??'')." title=".mb_substr((string)($it['title']??''),0,40)."\n";
}
