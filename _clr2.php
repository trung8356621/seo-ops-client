<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
$site=App\Models\Site::find(2);
// clear via empty then check how getMeta works
Illuminate\Support\Facades\DB::table('site_meta')->where('site_id',2)->whereIn('meta_key',[SiteSyncV3Schema::META_BASELINE_COMPLETED_AT, SiteSyncV3Schema::META_BASELINE_GENERATION])->delete();
echo "cleared rows\n";
echo "at=".json_encode($site->fresh()->getMeta(SiteSyncV3Schema::META_BASELINE_COMPLETED_AT))."\n";
