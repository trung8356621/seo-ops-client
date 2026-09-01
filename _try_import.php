<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Enums\ContentType;
$siteId=2;
$base=SeoArticle::query()->where('site_id',$siteId)->hasWpPostId();
$post=ArticleContentClassification::scopeNonTerm(ArticleContentClassification::scopeContentType(clone $base, ContentType::Post))->count();
$page=ArticleContentClassification::scopeNonTerm(ArticleContentClassification::scopeContentType(clone $base, ContentType::Page))->count();
$product=ArticleContentClassification::scopeNonTerm(ArticleContentClassification::scopeContentType(clone $base, ContentType::Product))->count();
$total=(clone $base)->count();
$terms=ArticleContentClassification::scopeTerms(clone $base)->count();
echo "post=$post page=$page product=$product other=".($total-$post-$page-$product)." total=$total terms=$terms\n";

// Try import 11278 manually via delta item
$site=App\Models\Site::find(2);
$client=app(Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client::class);
$body=['schema'=>'site_sync.v3','resource'=>'content','mode'=>'delta','limit'=>10,'cursor'=>null,'since'=>'2026-08-31T02:01:06+00:00','sync_generation'=>99];
$r=$client->records($site,$body);
$items=$r['records']['items']??[];
$target=null; foreach($items as $it){ if((int)($it['wp_id']??0)===11278) $target=$it; }
echo "target11278=".($target?json_encode(array_intersect_key($target,array_flip(['wp_id','op','title','status','type','content_type'])),JSON_UNESCAPED_UNICODE):'NOT_IN_DELTA')."\n";
if($target){
  $run=Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun::find(35);
  $imp=app(Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter::class);
  try {
    $counts=$imp->importContentChunk($site,$run,[$target]);
    echo "import_counts=".json_encode($counts)."\n";
  } catch(Throwable $e){ echo "IMPORT_EX=".$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n"; }
  $wal=Illuminate\Support\Facades\DB::connection('omi_seo_ai')->table('wordpress_article_links')->where('site_id',2)->where('wp_post_id',11278)->first();
  echo "after wal=".json_encode($wal)."\n";
}
