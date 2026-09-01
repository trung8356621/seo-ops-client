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
echo "post=$post page=$page product=$product other=".($total-$post-$page-$product)." total=$total\n";
$site=App\Models\Site::find(2);
$client=app(Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client::class);
$body=['schema'=>'site_sync.v3','resource'=>'content','mode'=>'delta','limit'=>10,'cursor'=>null,'since'=>'2026-08-31T02:01:06+00:00','sync_generation'=>99];
$r=$client->records($site,$body);
$items=$r['records']['items']??[];
$target=null; foreach($items as $it){ if((int)($it['wp_id']??0)===11278) $target=$it; }
echo "keys=".($target?implode(',',array_keys($target)):'none')."\n";
if($target){
  echo "subset=".json_encode(['wp_id'=>$target['wp_id']??null,'op'=>$target['op']??null,'title'=>$target['title']??null,'status'=>$target['status']??null,'type'=>$target['type']??null],JSON_UNESCAPED_UNICODE)."\n";
  $run=Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun::find(35);
  $imp=app(Omnichannel\Addons\SiteSync\Services\V3\SiteSyncV3BulkImporter::class);
  try {
    $counts=$imp->importContentChunk($site,$run,[$target]);
    echo "import_counts=".json_encode($counts)."\n";
  } catch(Throwable $e){ echo "IMPORT_EX=".$e->getMessage()."\n"; }
  $wal=Illuminate\Support\Facades\DB::connection('omi_seo_ai')->table('wordpress_article_links')->where('site_id',2)->where('wp_post_id',11278)->first();
  echo "after wal_article=".($wal->article_id??'null')."\n";
}
