<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
Auth::loginUsingId(2);
$rec=SeoDatabaseConnection::query()->where('is_active',true)->orderBy('id')->first();
if($rec) app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
$ri=SeoProjectRunItem::query()->where('run_id',189)->first();
$pr=PromptResult::find(1447);
echo "run_item_error=".mb_substr((string)($ri->error_message??$ri->message),0,400)."\n";
$snap=$pr->input_snapshot;
$vars=$snap['variables']??[];
echo "shape=".($vars['generation_shape']??$snap['generation_shape']??'')."\n";
echo "article_outline_len=".mb_strlen((string)($vars['article_outline']??''))."\n";
echo "article_vocab_len=".mb_strlen((string)($vars['article_vocabulary']??''))."\n";
echo "outline_preview=".substr((string)($vars['article_outline']??$vars['input']??''),0,80)."\n";
$usage=$pr->token_usage??[];
echo "token_keys=".implode(',',array_keys(is_array($usage)?$usage:[]))."\n";
if(isset($usage['breadcrumbs'])) echo "breadcrumbs=".json_encode($usage['breadcrumbs'])."\n";
if(isset($snap['sectioned_free_orchestrator'])) echo "sf=1\n";
echo "snap_keys=".implode(',',array_keys(is_array($snap)?$snap:[]))."\n";
// child results
$children=PromptResult::query()->where('parent_id',1447)->orderBy('id')->get(['id','status','canonical_prompt_key','error_message','output_text']);
echo "children=".$children->count()."\n";
foreach($children as $c){ echo " child {$c->id} {$c->status} ".mb_substr((string)$c->error_message,0,120)."\n"; }