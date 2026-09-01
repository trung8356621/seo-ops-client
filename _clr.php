<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
// revert fake baseline
$site=App\Models\Site::find(2);
$site->setMeta(Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema::META_BASELINE_COMPLETED_AT, null);
$site->setMeta(Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema::META_BASELINE_GENERATION, null);
echo "baseline cleared\n";
echo "at=".json_encode($site->getMeta(Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema::META_BASELINE_COMPLETED_AT))."\n";
