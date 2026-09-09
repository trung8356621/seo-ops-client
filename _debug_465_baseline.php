<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use App\Models\SeoDatabaseConnection;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
if ($rec) {
    app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
}

$task = SeoProjectTask::withTrashed()->find(465);
if (!$task) { fwrite(STDERR, "TASK 465 NOT FOUND\n"); exit(1); }
$project = SeoProject::query()->find((int)$task->project_id);
$article = $task->article_id ? SeoArticle::withTrashed()->find((int)$task->article_id) : null;
$body = $article ? (string)($article->body ?? '') : '';

$baseline = [
  'auth_uid' => Auth::id(),
  'seo_conn' => SeoConnectionContext::current()?->id,
  'project_id' => (int)$task->project_id,
  'project_name' => $project?->name,
  'project_month' => (string)($project?->month ?? ''),
  'task_id' => (int)$task->id,
  'article_id' => $task->article_id ? (int)$task->article_id : null,
  'site_id' => (int)$task->site_id,
  'keyword' => $task->keyword,
  'title' => $task->title ?? $article?->title,
  'task_status' => $task->status,
  'article_status' => $article?->status,
  'article_body_length' => mb_strlen(strip_tags($body)),
  'outline_state' => $article === null ? 'no_article' : ($article->outline === null ? 'null' : gettype($article->outline)),
  'vocabulary_state' => $article === null ? 'no_article' : ($article->vocabulary === null ? 'null' : gettype($article->vocabulary)),
  'generation_blocked_at' => (string)($task->generation_blocked_at ?? ''),
  'max_prompt_result_id' => (int)(PromptResult::query()->max('id') ?? 0),
];

$userId = (int)Auth::id();
$priorities = app(AiModelPriorityService::class);
$router = app(AiModelRouterService::class);
$health = app(AiRuntimeHealthService::class);
$aiCenter = [];
foreach ([
  'Reasoning Text' => [AiModelArea::TextReasoning, 'text.reasoning'],
  'Long-form Text' => [AiModelArea::TextLongform, 'text.longform'],
] as $label => $pair) {
  $area = $pair[0];
  $profile = $pair[1];
  $models = $priorities->areaEnabledModels($userId, $area);
  $rows = [];
  $prio = 0;
  foreach ($models as $model) {
    $prio++;
    $conn = $model->apiConnection;
    $rows[] = [
      'logical_priority' => $prio,
      'model_id' => (int)$model->id,
      'logical_model' => (string)($model->raw_model_name ?? ''),
      'display_name' => (string)($model->name ?? ''),
      'connection_id' => (int)($conn->id ?? 0),
      'connection_name' => (string)($conn->name ?? ''),
      'provider' => (string)($conn->provider ?? ''),
      'status' => (string)($model->status ?? ''),
    ];
  }
  $ctx = new AiRoutingContext(userId: $userId, freeOnly: false, allowLegacyFallback: false);
  $timeline = [];
  $firstUsable = null;
  try {
    $all = $router->resolveAll($profile, $ctx);
    foreach ($all as $i => $c) {
      $skip = $health->skipReason($userId, $c);
      $timeline[] = [
        'idx' => $i + 1,
        'logical' => $c->logicalModelKey(),
        'physical' => $c->physicalRouteKey(),
        'provider' => $c->provider,
        'is_free' => $c->isFree,
        'cost_class' => $c->isFree ? 'free' : 'paid',
        'connection_id' => (int)($c->connection->id ?? 0),
        'connection_name' => (string)($c->connection->name ?? ''),
        'health_skip' => $skip,
      ];
    }
    $first = $router->resolveFirstAttemptable($profile, $ctx);
    $firstUsable = [
      'logical' => $first->logicalModelKey(),
      'physical' => $first->physicalRouteKey(),
      'provider' => $first->provider,
      'connection_id' => (int)($first->connection->id ?? 0),
      'connection_name' => (string)($first->connection->name ?? ''),
      'is_free' => $first->isFree,
      'cost_class' => $first->isFree ? 'free' : 'paid',
      'expected_shape' => $first->isFree ? 'SPLIT/sectioned' : 'SINGLE/single_pass',
    ];
  } catch (Throwable $e) {
    $firstUsable = ['error' => $e->getMessage()];
  }
  $aiCenter[$label] = [
    'profile' => $profile,
    'logical_models' => $rows,
    'physical_route_timeline' => $timeline,
    'first_usable' => $firstUsable,
  ];
}

$out = ['baseline' => $baseline, 'ai_center' => $aiCenter];
$path = __DIR__ . '/storage/logs/debug_465_baseline.json';
@mkdir(dirname($path), 0777, true);
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
echo "WROTE ", $path, "\n";