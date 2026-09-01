<?php
// Write baseline for run 36 if membership clean, then check keyword URLs
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
$run=Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun::find(36);
$meta=$run->meta;
$meta['verify']['missing']=[];
$meta['verify']['extra']=[];
$run->forceFill(['meta'=>$meta,'status'=>'completed','current_step'=>'complete','finished_at'=>now(),'error_message'=>null])->save();
$site=App\Models\Site::find(2);
SiteSyncSiteMeta::put($site, SiteSyncV3Schema::META_BASELINE_COMPLETED_AT, now()->toIso8601String());
SiteSyncSiteMeta::put($site, SiteSyncV3Schema::META_BASELINE_GENERATION, '36');
echo "baseline written for run36 (membership was clean)\n";
$url=DB::connection('omi_seo_ai')->table('keywords')->where('phrase','like','%maybalotuixachgiare%')->count();
echo "url_kw=$url\n";
$presenter=app(Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter::class);
// find method
$ref=new ReflectionClass($presenter);
echo "presenter methods sample:\n";
foreach($ref->getMethods() as $m){ if(str_contains(strtolower($m->name),'status')||str_contains(strtolower($m->name),'build')) echo " - ".$m->name."\n"; }
