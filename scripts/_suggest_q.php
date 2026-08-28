<?php
declare(strict_types=1);
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
$root = dirname(__DIR__);
require $root."/vendor/autoload.php";
$app = require $root."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();

$base = Keyword::query()->where("type", Keyword::TYPE_SUGGEST)->where("source","ai_generated");
echo "raw suggest+ai_gen=".$base->count()."\n";
echo "forSite(7)=".(clone $base)->forSite(7)->count()."\n";

$q = Keyword::query()->where("type",Keyword::TYPE_SUGGEST)->where("source","ai_generated")->forSite(7);
$q2 = InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases(clone $q);
echo "after anchor filter=".$q2->count()."\n";

// simulate word count if method accessible
$ref = new ReflectionClass(KeywordResource::class);
if ($ref->hasMethod("applyMinimumKeywordWordCount")) {
  $m = $ref->getMethod("applyMinimumKeywordWordCount");
  $m->setAccessible(true);
  $q3 = $m->invoke(null, Keyword::query()->where("type",Keyword::TYPE_SUGGEST)->where("source","ai_generated")->forSite(7));
  echo "after min word count=".$q3->count()."\n";
}

$sample = Keyword::query()->where("type",Keyword::TYPE_SUGGEST)->where("source","ai_generated")->forSite(7)->limit(5)->get(["id","phrase","type","source"]);
foreach ($sample as $k) echo "#{$k->id} {$k->phrase}\n";