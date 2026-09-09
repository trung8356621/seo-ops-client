<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;

Auth::loginUsingId(2);
$rec=SeoDatabaseConnection::query()->where('is_active',true)->orderBy('id')->first();
if($rec) app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);

$a=SeoArticle::find(13637);
$t=SeoProjectTask::find(465);
$ri=SeoProjectRunItem::query()->where('run_id',188)->first();
$pr1442=PromptResult::find(1442);
$pr1443=PromptResult::find(1443);
$pr1444=PromptResult::find(1444);

$out=[
  'task'=>['id'=>465,'status'=>$t->status,'article_id'=>$t->article_id,'keyword'=>$t->keyword],
  'article'=>[
    'id'=>13637,
    'title'=>$a->title,
    'status'=>$a->status,
    'body_len'=>mb_strlen(strip_tags((string)$a->body)),
    'outline_is_null'=>$a->outline===null,
    'vocabulary_is_null'=>$a->vocabulary===null,
    'attrs_keys'=>array_keys($a->getAttributes()),
  ],
  'run_item'=> $ri? $ri->toArray():null,
  'pr1442'=>[
    'status'=>$pr1442->status,
    'output_len'=>mb_strlen((string)$pr1442->output_text),
    'output_preview'=>mb_substr((string)$pr1442->output_text,0,300),
    'has_h2'=>(bool)preg_match('/^##\s+/m',(string)$pr1442->output_text),
    'cols'=>[
      'content_project_id'=>$pr1442->content_project_id??null,
      'project_item_id'=>$pr1442->project_item_id??null,
      'run_id'=>$pr1442->run_id??null,
      'node_id'=>$pr1442->node_id??null,
      'correlation_id'=>$pr1442->correlation_id??null,
      'canonical_prompt_key'=>$pr1442->canonical_prompt_key,
      'stage'=>$pr1442->stage,
    ],
  ],
  'pr1443'=>[
    'status'=>$pr1443->status,
    'output_len'=>mb_strlen((string)$pr1443->output_text),
    'output_preview'=>mb_substr((string)$pr1443->output_text,0,300),
    'cols'=>[
      'content_project_id'=>$pr1443->content_project_id??null,
      'project_item_id'=>$pr1443->project_item_id??null,
      'run_id'=>$pr1443->run_id??null,
      'node_id'=>$pr1443->node_id??null,
      'correlation_id'=>$pr1443->correlation_id??null,
    ],
  ],
  'pr1444'=>[
    'status'=>$pr1444->status,
    'error'=>$pr1444->error_message,
    'snap_keys'=>array_keys(is_array($pr1444->input_snapshot)?$pr1444->input_snapshot:[]),
    'snap_vars'=> is_array($pr1444->input_snapshot['variables']??null)? array_intersect_key($pr1444->input_snapshot['variables'], array_flip([
      'generation_shape','generation_shape_source','shape_decision_cost_class','shape_decision_physical_route','article_vocabulary','outline','article_outline','title','keyword','pass_mode'
    ])):null,
    'outline_in_snap_len'=> mb_strlen((string)($pr1444->input_snapshot['variables']['article_outline'] ?? $pr1444->input_snapshot['variables']['outline'] ?? '')),
    'vocab_in_snap_len'=> mb_strlen((string)($pr1444->input_snapshot['variables']['article_vocabulary'] ?? $pr1444->input_snapshot['variables']['vocabulary'] ?? '')),
    'cols'=>[
      'content_project_id'=>$pr1444->content_project_id??null,
      'project_item_id'=>$pr1444->project_item_id??null,
      'run_id'=>$pr1444->run_id??null,
      'node_id'=>$pr1444->node_id??null,
      'correlation_id'=>$pr1444->correlation_id??null,
      'canonical_prompt_key'=>$pr1444->canonical_prompt_key,
      'stage'=>$pr1444->stage,
    ],
    'token_usage_keys'=>array_keys(is_array($pr1444->token_usage)?$pr1444->token_usage:[]),
  ],
];
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";