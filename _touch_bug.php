<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\WordPress\Services\WordpressArticleLinkWriter;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
$article=SeoArticle::query()->create(['site_id'=>2,'title'=>'TOUCH-TEST-'.time(),'status'=>'draft','body'=>null]);
$article->forceFill(['wp_post_id'=>999002])->save();
echo "after forceFill wpid=".(WordpressArticleLink::where('article_id',$article->id)->value('wp_post_id'))." loaded=".json_encode($article->relationLoaded('wordpressLink'))."\n";
// simulate importer without refreshing relation
app(WordpressArticleLinkWriter::class)->upsert($article,['wp_post_id'=>999002,'site_id'=>2,'last_synced_at'=>now(),'last_seen_sync_generation'=>99]);
echo "after writer wpid=".(WordpressArticleLink::where('article_id',$article->id)->value('wp_post_id'))." rel=".json_encode($article->wordpressLink?->wp_post_id)."\n";
app(ArticleLastSavedTimestampService::class)->touchSynced($article);
echo "after touchSynced wpid=".(WordpressArticleLink::where('article_id',$article->id)->value('wp_post_id'))."\n";
// reproduce stale null relation
$article2=SeoArticle::query()->create(['site_id'=>2,'title'=>'TOUCH-TEST2-'.time(),'status'=>'draft','body'=>null]);
$article2->setRelation('wordpressLink', null);
$article2->forceFill(['wp_post_id'=>999003])->save();
echo "a2 after forceFill db=".(WordpressArticleLink::where('article_id',$article2->id)->value('wp_post_id'))." relLoaded=".json_encode($article2->relationLoaded('wordpressLink'))." relVal=".json_encode($article2->getRelation('wordpressLink'))."\n";
app(WordpressArticleLinkWriter::class)->upsert($article2,['wp_post_id'=>999003,'site_id'=>2,'last_synced_at'=>now(),'last_seen_sync_generation'=>99]);
app(ArticleLastSavedTimestampService::class)->touchSynced($article2);
echo "a2 after touch db=".(WordpressArticleLink::where('article_id',$article2->id)->value('wp_post_id'))."\n";
WordpressArticleLink::whereIn('wp_post_id',[999002,999003])->delete();
SeoArticle::whereIn('id',[$article->id,$article2->id])->delete();
