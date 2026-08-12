<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use App\Models\Site;

$run = SeoSiteSyncRun::query()->find(1);
$site = Site::query()->find($run->site_id);
echo "site=".($site?->id)." ".($site?->domain)."\n";

try {
  $runner = app(SiteSyncStepRunner::class);
  $ref = new ReflectionClass($runner);
  $m = $ref->getMethod('syncUrlCatalog');
  $m->setAccessible(true);
  $m->invoke($runner, $site, $run);
  echo "SUCCESS\n";
} catch (Throwable $e) {
  echo "FAIL: ".$e->getMessage()."\n";
  foreach ($e->getTrace() as $i => $frame) {
    if ($i > 25) break;
    $file = $frame['file'] ?? '';
    $line = $frame['line'] ?? '';
    $fn = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
    echo "#$i $fn @ $file:$line\n";
  }
  if ($prev = $e->getPrevious()) {
    echo "PREV: ".$prev->getMessage()."\n";
  }
}
