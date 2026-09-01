<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\WordpressArticleLinkWriter;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
$article=SeoArticle::query()->create(['site_id'=>2,'title'=>'WPID-TEST-'.time(),'status'=>'draft','body'=>null]);
echo "created article {$article->id}\n";
$article->forceFill(['wp_post_id'=>999001])->save();
$link=WordpressArticleLink::query()->where('article_id',$article->id)->first();
echo "after forceFill link=".json_encode($link)."\n";
app(WordpressArticleLinkWriter::class)->upsert($article,['wp_post_id'=>999001,'site_id'=>2,'last_synced_at'=>now()]);
$link=WordpressArticleLink::query()->where('article_id',$article->id)->first();
echo "after writer link=".json_encode($link)."\n";
// cleanup
WordpressArticleLink::query()->where('article_id',$article->id)->delete();
$article->delete();
