<?php
require __DIR__."/vendor/autoload.php";
$app=require __DIR__."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Auth;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
Auth::loginUsingId(2);
$rec=SeoDatabaseConnection::query()->where("is_active",true)->first();
app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
$run=SeoProjectRun::query()->orderByDesc("id")->first();
echo "latest_run={$run->id} status={$run->status} s={$run->succeeded} f={$run->failed} t={$run->total}\n";
$items=SeoProjectRunItem::query()->where("run_id",$run->id)->get();
foreach($items as $i){ echo "item {$i->id} status={$i->status} err=".mb_substr((string)($i->error_message??""),0,200)."\n"; }
$max=(int)PromptResult::query()->max("id");
$prs=PromptResult::query()->where("id",">",1451)->orderBy("id")->get(["id","canonical_prompt_key","status","parent_id","error_message"]);
echo "new_prs=".$prs->count()." max=$max\n";
foreach($prs as $pr){
  echo "PR{$pr->id} key={$pr->canonical_prompt_key} status={$pr->status} parent={$pr->parent_id} err=".mb_substr((string)$pr->error_message,0,120)."\n";
}
