<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;
use Omnichannel\Addons\AiPrompt\Models\Prompt;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

function say(string $m): void { echo '['.date('H:i:s')."] $m\n"; }
function jdump(string $p, mixed $d): void { file_put_contents($p, json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n"); }

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
if ($rec) {
    app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
}
say('auth_uid=' . Auth::id() . ' seo_conn=' . (SeoConnectionContext::current()?->id ?? 'null'));

$taskId = 465;
$task = SeoProjectTask::query()->findOrFail($taskId);
$project = SeoProject::query()->findOrFail((int)$task->project_id);
$projectId = (int)$project->id;

$outDir = __DIR__ . '/storage/logs/cp_debug_465_' . date('Ymd_His');
mkdir($outDir, 0777, true);

$baseline = [
  'project_id'=>$projectId,'project_item_id'=>$taskId,'article_id'=>$task->article_id,
  'task_status'=>$task->status,'keyword'=>$task->keyword,'site_id'=>(int)$task->site_id,
];
jdump("$outDir/00_baseline.json", $baseline);
say('baseline '.json_encode($baseline, JSON_UNESCAPED_UNICODE));

$maxPr = (int)(PromptResult::query()->max('id') ?? 0);
say("max_pr_before=$maxPr");

$actor = ActorContext::user(2, (int)$task->site_id, 'debug-cp-465-'.time());
$cmd = new GenerateProjectItemsCommand(
  ContentProjectPublicRef::project($projectId),
  [ContentProjectPublicRef::item($taskId)],
  'full',
  false,
  ['rerun_sync' => true],
);
say('dispatching GenerateProjectItemsCommand...');
$result = app(ContentProjectCommandBus::class)->dispatch($cmd, $actor);
$props = [];
foreach ((new ReflectionClass($result))->getProperties() as $p) {
  $p->setAccessible(true);
  $props[$p->getName()] = $p->getValue($result);
}
jdump("$outDir/01_command_result.json", $props);
say('result='.json_encode($props, JSON_UNESCAPED_UNICODE));

$ref = $props['metadata']['execution_ref'] ?? $props['data']['execution_ref'] ?? null;
$runId = 0;
if (is_string($ref) && $ref !== '') {
  try { $runId = (int) ContentProjectPublicRef::decodeExecution($ref); } catch (Throwable $e) { say('decode err '.$e->getMessage()); }
}
if ($runId <= 0) {
  $runId = (int)(SeoProjectRun::query()->where('project_id', $projectId)->orderByDesc('id')->value('id') ?? 0);
}
say("run_id=$runId");

$last = '';
$deadline = time() + 1800;
while (time() < $deadline) {
  $run = SeoProjectRun::query()->find($runId);
  if (!$run) { say('run missing'); break; }
  $items = SeoProjectRunItem::query()->where('run_id', $runId)->get()->map(fn($i)=>[
    'id'=>(int)$i->id,'task_id'=>(int)$i->task_id,'status'=>$i->status,'attempt'=>$i->attempt??null,
    'error'=>mb_substr((string)($i->error??$i->last_error??$i->message??''),0,500),
  ])->all();
  $line = $run->status." s={$run->succeeded} f={$run->failed} t={$run->total}";
  if ($line !== $last) {
    say("RUN $line");
    $last=$line;
    jdump("$outDir/03_poll.json", ['status'=>$run->status,'items'=>$items,'at'=>date('c')]);
  }
  if (!in_array((string)$run->status, ['running','queued','pending'], true)) break;
  sleep(3);
}

$task->refresh();
$articleId = $task->article_id ? (int)$task->article_id : null;
$article = $articleId ? SeoArticle::query()->find($articleId) : null;
$run = SeoProjectRun::query()->find($runId);
jdump("$outDir/04_final.json", [
  'run'=> $run?['id'=>(int)$run->id,'status'=>$run->status,'succeeded'=>$run->succeeded,'failed'=>$run->failed,'total'=>$run->total]:null,
  'task'=>['id'=>$taskId,'status'=>$task->status,'article_id'=>$task->article_id],
  'article'=> $article ? [
    'id'=>$articleId,
    'title'=>$article->title,
    'status'=>$article->status,
    'body_len'=>mb_strlen(strip_tags((string)$article->body)),
    'outline_type'=>$article->outline===null?'null':gettype($article->outline),
    'vocabulary_type'=>$article->vocabulary===null?'null':gettype($article->vocabulary),
    'outline_preview'=>is_string($article->outline)?mb_substr($article->outline,0,500):(is_array($article->outline)?array_slice($article->outline,0,5):null),
  ] : null,
]);

$prs = PromptResult::query()->where('id','>',$maxPr)->orderBy('id')->get();
$stages=[];
foreach ($prs as $pr) {
  $snap = is_array($pr->input_snapshot)?$pr->input_snapshot:[];
  $aid = (int)($snap['article_id'] ?? ($snap['variables']['article_id'] ?? 0));
  $related = ((int)$pr->project_item_id === $taskId) || ((int)$pr->run_id === $runId) || ($articleId && $aid === $articleId);
  if (!$related && ((int)$pr->project_item_id > 0 && (int)$pr->project_item_id !== $taskId)) continue;
  $usage = is_array($pr->token_usage)?$pr->token_usage:[];
  $routing = is_array($usage['routing']??null)?$usage['routing']:[];
  $attempts = PromptResultRoutingAttempt::query()->where('prompt_result_id',(int)$pr->id)->orderBy('sequence')->get()->map(fn($r)=>$r->toArray())->all();
  $prompt = Prompt::query()->find($pr->prompt_id);
  $version = $pr->prompt_version_id ? PromptVersion::query()->find($pr->prompt_version_id) : null;
  $vars = is_array($snap['variables'] ?? null) ? $snap['variables'] : [];
  $stage = [
    'prompt_result_id'=>(int)$pr->id,
    'canonical_prompt_key'=>$pr->canonical_prompt_key,
    'stage'=>$pr->stage,
    'prompt_name'=>$prompt?->name ?? $prompt?->title ?? null,
    'prompt_version_id'=>$pr->prompt_version_id,
    'prompt_version_label'=>$version?->label ?? $version?->version_label ?? $version?->version ?? null,
    'status'=>$pr->status,
    'failure_category'=>$pr->failure_category,
    'failure_code'=>$pr->failure_code,
    'error_message'=>$pr->error_message,
    'content_project_id'=>$pr->content_project_id ?? null,
    'project_item_id'=>$pr->project_item_id,
    'run_id'=>$pr->run_id,
    'node_id'=>$pr->node_id,
    'retry_attempt'=>$pr->retry_attempt ?? null,
    'correlation_id'=>$pr->correlation_id ?? null,
    'output_len'=>mb_strlen((string)($pr->output_text??'')),
    'generation_shape'=>$vars['generation_shape'] ?? $snap['generation_shape'] ?? ($routing['generation_shape'] ?? null),
    'generation_shape_source'=>$vars['generation_shape_source'] ?? $snap['generation_shape_source'] ?? null,
    'shape_decision_cost_class'=>$vars['shape_decision_cost_class'] ?? $snap['shape_decision_cost_class'] ?? null,
    'shape_decision_physical_route'=>$vars['shape_decision_physical_route'] ?? $snap['shape_decision_physical_route'] ?? null,
    'model_area'=>$routing['model_area'] ?? $routing['profile'] ?? null,
    'routing_attempts_runtime'=>$routing['routing_attempts'] ?? null,
    'persisted_routing_attempts'=>$attempts,
    'validation'=>$routing['validation'] ?? ($usage['validation'] ?? null),
    'validation_contract'=>$usage['validation_contract'] ?? ($snap['validation_contract'] ?? null),
  ];
  $stages[]=$stage;
  $safe = preg_replace('/[^a-z0-9._-]+/i','_', (string)($pr->canonical_prompt_key ?: $pr->stage ?: 'unknown'));
  jdump("$outDir/stage_{$pr->id}_{$safe}.json", $stage);
}
jdump("$outDir/05_stages.json", $stages);
say('stages='.count($stages).' OUTDIR='.$outDir);
echo "OUTDIR=$outDir\n";