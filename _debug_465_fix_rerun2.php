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
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

function say(string $m): void { echo '['.date('H:i:s')."] $m\n"; }
function jdump(string $p, mixed $d): void { file_put_contents($p, json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n"); }

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
if ($rec) app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);

$taskId = 465;
$task = SeoProjectTask::query()->findOrFail($taskId);
$project = SeoProject::query()->findOrFail((int)$task->project_id);
$projectId = (int)$project->id;
$outDir = __DIR__ . '/storage/logs/cp_debug_465_fix2_' . date('Ymd_His');
mkdir($outDir, 0777, true);

$maxPr = (int)(PromptResult::query()->max('id') ?? 0);
say("max_pr=$maxPr article={$task->article_id} status={$task->status}");

$actor = ActorContext::user(2, (int)$task->site_id, 'debug-cp-465-fix2-'.time());

// Prefer full outline→content rebuild to exercise split handoff end-to-end.
$cmd = new RerunProjectItemStepCommand(
  ContentProjectPublicRef::project($projectId),
  [ContentProjectPublicRef::item($taskId)],
  ContentProjectRerunFromStep::Outline,
  true, // include downstream content
  null,
  'full',
  true, // sync
);
say('dispatching RerunProjectItemStepCommand from Outline + downstream sync...');
$result = app(ContentProjectCommandBus::class)->dispatch($cmd, $actor);
$props = [];
foreach ((new ReflectionClass($result))->getProperties() as $p) { $p->setAccessible(true); $props[$p->getName()] = $p->getValue($result); }
jdump("$outDir/01_command_result.json", $props);
say('result='.json_encode($props, JSON_UNESCAPED_UNICODE));

$ref = $props['metadata']['execution_ref'] ?? null;
$runId = 0;
if (is_string($ref) && $ref !== '') {
  try { $runId = (int) ContentProjectPublicRef::decodeExecution($ref); } catch (Throwable $e) { say($e->getMessage()); }
}
if ($runId <= 0) {
  $runId = (int)(SeoProjectRun::query()->where('project_id', $projectId)->orderByDesc('id')->value('id') ?? 0);
}
say("run_id=$runId");

$last='';
$deadline = time() + 2400;
while (time() < $deadline) {
  $run = SeoProjectRun::query()->find($runId);
  if (!$run) break;
  $items = SeoProjectRunItem::query()->where('run_id', $runId)->get()->map(fn($i)=>[
    'id'=>(int)$i->id,'task_id'=>(int)$i->task_id,'status'=>$i->status,
    'error'=>mb_substr((string)($i->error_message??$i->message??''),0,500),
  ])->all();
  $line = $run->status." s={$run->succeeded} f={$run->failed} t={$run->total}";
  if ($line !== $last) { say("RUN $line"); $last=$line; jdump("$outDir/03_poll.json", ['status'=>$run->status,'items'=>$items]); }
  if (!in_array((string)$run->status, ['running','queued','pending'], true)) break;
  sleep(5);
}

$task->refresh();
$articleId = $task->article_id ? (int)$task->article_id : null;
$article = $articleId ? SeoArticle::query()->find($articleId) : null;
$run = SeoProjectRun::query()->find($runId);
jdump("$outDir/04_final.json", [
  'run'=> $run?['id'=>(int)$run->id,'status'=>$run->status,'succeeded'=>$run->succeeded,'failed'=>$run->failed,'total'=>$run->total]:null,
  'task'=>['id'=>$taskId,'status'=>$task->status,'article_id'=>$task->article_id],
  'article'=> $article ? [
    'id'=>$articleId,'title'=>$article->title,'status'=>$article->status,
    'body_len'=>mb_strlen(strip_tags((string)$article->body)),
    'body_words'=>str_word_count(strip_tags((string)$article->body)),
  ] : null,
]);

$prs = PromptResult::query()->where('id','>',$maxPr)->orderBy('id')->get();
$stages=[];
foreach ($prs as $pr) {
  $snap = is_array($pr->input_snapshot)?$pr->input_snapshot:[];
  $vars = is_array($snap['variables'] ?? null) ? $snap['variables'] : [];
  $stages[] = [
    'prompt_result_id'=>(int)$pr->id,
    'canonical_prompt_key'=>$pr->canonical_prompt_key,
    'stage'=>$pr->stage,
    'status'=>$pr->status,
    'error_message'=>mb_substr((string)$pr->error_message,0,300),
    'run_id'=>$pr->run_id,
    'parent_id'=>$pr->parent_id ?? null,
    'output_len'=>mb_strlen((string)($pr->output_text??'')),
    'generation_shape'=>$vars['generation_shape'] ?? $snap['generation_shape'] ?? null,
  ];
}
jdump("$outDir/05_stages.json", $stages);
say('stages='.count($stages).' OUTDIR='.$outDir);
echo "OUTDIR=$outDir\n";