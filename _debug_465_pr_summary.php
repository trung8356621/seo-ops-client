<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
Auth::loginUsingId(2);
$rec=SeoDatabaseConnection::query()->where('is_active',true)->orderBy('id')->first();
if($rec) app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
foreach([1442,1443,1444] as $id){
  $pr=PromptResult::find($id);
  $snap=$pr->input_snapshot;
  $vars=is_array($snap['variables']??null)?$snap['variables']:[];
  echo "PR$id status={$pr->status} key={$pr->canonical_prompt_key}\n";
  echo "  project_item_id=".var_export($pr->project_item_id,true)." run_id=".var_export($pr->run_id,true)." node_id=".var_export($pr->node_id,true)." correlation=".var_export($pr->correlation_id,true)." content_project_id=".var_export($pr->content_project_id??null,true)."\n";
  echo "  shape=".($vars['generation_shape']??$snap['generation_shape']??'null')." source=".($vars['generation_shape_source']??$snap['generation_shape_source']??'null')." cost=".($vars['shape_decision_cost_class']??'null')."\n";
  if($id===1444){
    echo "  error=".str_replace("\n"," | ",(string)$pr->error_message)."\n";
    echo "  snap_has_outline_markers=".(str_contains(json_encode($snap),'START_TASK_1_OUTLINE')?'Y':'N')."\n";
    echo "  snap_has_vocab_markers=".(str_contains(json_encode($snap),'START_TASK_2_VOCABULARY')?'Y':'N')."\n";
    echo "  vocab_var_len=".mb_strlen((string)($vars['article_vocabulary']??$vars['vocabulary']??''))."\n";
    echo "  outline_var_len=".mb_strlen((string)($vars['article_outline']??$vars['outline']??$vars['input']??''))."\n";
  }
}