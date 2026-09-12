<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner;
use Omnichannel\Addons\AiPrompt\Services\WritingSectionPromptCompiler;
use Omnichannel\Addons\AiPrompt\Support\WritingMultiplePassPromptIsolationGuard;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;

function log_ndjson(string $hypothesisId, string $location, string $message, array $data): void
{
    $payload = [
        'sessionId' => 'd04245',
        'runId' => 'isolation-trace',
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) round(microtime(true) * 1000),
    ];
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";
    file_put_contents(__DIR__.'/debug-d04245.log', $line, FILE_APPEND | LOCK_EX);
}

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
if ($rec) {
    app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);
}

$pr = PromptResult::query()->findOrFail(1447);
$snap = is_array($pr->input_snapshot) ? $pr->input_snapshot : [];
$vars = is_array($snap['variables'] ?? null) ? $snap['variables'] : [];

// Fallback: load outline from run item artifacts if parent stub vars empty.
$runItem = SeoProjectRunItem::query()->where('run_id', 189)->where('task_id', 465)->first();
$output = is_array($runItem?->output) ? $runItem->output : [];
$artifacts = is_array($runItem?->artifacts) ? $runItem->artifacts : [];

$fullOutline = trim((string) (
    $vars['article_outline']
    ?? $vars['outline']
    ?? $output['sections']['outline']
    ?? $artifacts['ArticleOutline']
    ?? ''
));

if ($fullOutline === '' && is_array($output['ports'] ?? null)) {
    $fullOutline = trim((string) ($output['ports']['total'] ?? ''));
}

// Prefer typed from earlier successful outline PRs
if ($fullOutline === '' || str_contains($fullOutline, '[START_TASK')) {
    $struct = PromptResult::query()->find(1445);
    $fullOutline = trim((string) ($struct?->output_text ?? $fullOutline));
}

$vocab = trim((string) (
    $vars['article_vocabulary']
    ?? $output['sections']['vocabulary']
    ?? ''
));
if ($vocab === '') {
    $vocabPr = PromptResult::query()->find(1446);
    $vocab = trim((string) ($vocabPr?->output_text ?? ''));
}

$promptId = (int) ($pr->prompt_id ?? 0);
$prompt = SeoPrompt::query()->findOrFail($promptId);

$partHeadings = [];
foreach ($prompt->resolvedParts() as $part) {
    if ((string) $part->role === 'global_constraints') {
        continue;
    }
    $roleHeadings = [
        'system' => 'System',
        'context' => 'Context',
        'task' => 'Task',
        'sub_task' => 'Sub-task',
        'constraints' => 'Constraints',
        'examples' => 'Examples',
        'output' => 'Output',
    ];
    $heading = $roleHeadings[$part->role] ?? ucfirst((string) $part->role);
    if (in_array($part->role, ['task', 'sub_task'], true) && filled($part->name)) {
        $heading .= ': '.(string) $part->name;
    }
    $partHeadings[] = '## '.$heading;
}

$rows = (new OutlineStructuredRowsNormalizer())->normalize($fullOutline);
$plan = (new WritingMultiplePassStepPlanner())->planFromRows($rows);
$units = $plan->units;
$unit = $units[0] ?? null;

log_ndjson('H1', 'isolation_trace.php:baseline', 'pr1447_and_outline', [
    'prompt_id' => $promptId,
    'parts_count' => count($partHeadings),
    'role_heading_hashes' => count($partHeadings),
    'full_outline_len' => mb_strlen($fullOutline),
    'full_h2' => preg_match_all('/^##\s+/mu', $fullOutline) ?: 0,
    'full_h3' => preg_match_all('/^###\s+/mu', $fullOutline) ?: 0,
    'vocab_len' => mb_strlen($vocab),
    'planned_sections' => count($units),
    'first_section_id' => $unit?->sectionId,
    'first_slice_h2' => $unit ? (preg_match_all('/^##\s+/mu', $unit->scopeMarkdown()) ?: 0) : null,
    'first_slice_preview' => $unit ? mb_substr($unit->scopeMarkdown(), 0, 200) : null,
]);

$baseVars = [
    'title' => 'Effortless Chic',
    'post_title' => 'Effortless Chic',
    'keyword' => 'Effortless Chic',
    'focus_keyword' => 'Effortless Chic',
    'language' => 'vi',
    'article_outline' => $fullOutline,
    'outline' => $fullOutline,
    'input' => $fullOutline,
    'article_writing_raw_input' => $fullOutline,
    'article_vocabulary' => $vocab,
    'generation_shape' => 'sectioned',
    'generation_strategy' => 'sectioned',
];

// Count ## in each base var before compile
$varH2 = [];
foreach ($baseVars as $k => $v) {
    if (! is_string($v)) {
        continue;
    }
    $varH2[$k] = preg_match_all('/^##\s+/mu', $v) ?: 0;
}
log_ndjson('H2', 'isolation_trace.php:pre_compile_vars', 'vars_before_isolation', [
    'var_h2_line_start' => $varH2,
    'input_has_full' => ($varH2['input'] ?? 0) >= 3,
]);

$compiler = app(WritingSectionPromptCompiler::class);
$compiled = $compiler->compile($prompt, $baseVars, $unit);

$compiledH2Loose = substr_count($compiled, '## ');
$compiledH2Line = preg_match_all('/^##\s+/mu', $compiled) ?: 0;
$hasFullOutlineChunk = mb_strlen($fullOutline) > 200 && str_contains($compiled, mb_substr(trim($fullOutline), 0, 120));
$slice = $unit->scopeMarkdown();
$siblingLeak = false;
foreach ($units as $other) {
    if ($other->sectionId === $unit->sectionId) {
        continue;
    }
    $otherTitle = trim((string) ($other->parentH2 ?? ''));
    if ($otherTitle !== '' && str_contains($compiled, $otherTitle) && ! str_contains($slice, $otherTitle)) {
        $siblingLeak = true;
        break;
    }
}

log_ndjson('H3', 'isolation_trace.php:compiled', 'compiled_section_prompt_metrics', [
    'compiled_len' => mb_strlen($compiled),
    'compiled_hash_loose_count' => $compiledH2Loose,
    'compiled_hash_line_start' => $compiledH2Line,
    'role_headings_alone' => count($partHeadings),
    'full_h2' => preg_match_all('/^##\s+/mu', $fullOutline) ?: 0,
    'slice_h2' => preg_match_all('/^##\s+/mu', $slice) ?: 0,
    'guard_would_fire_loose' => (
        (preg_match_all('/^##\s+/mu', $fullOutline) ?: 0) >= 3
        && (preg_match_all('/^##\s+/mu', $slice) ?: 0) <= 1
        && $compiledH2Loose >= (preg_match_all('/^##\s+/mu', $fullOutline) ?: 0)
    ),
    'has_full_outline_prefix_chunk' => $hasFullOutlineChunk,
    'sibling_title_leak' => $siblingLeak,
    'has_writing_scope' => str_contains($compiled, 'WRITING SCOPE: SECTION'),
    'has_current_slice_marker' => str_contains($compiled, 'CURRENT OUTLINE SLICE'),
    'outline_after_in_compiled_preview' => mb_substr($compiled, max(0, mb_strpos($compiled, 'CURRENT OUTLINE SLICE') ?: 0), 300),
]);

// Which substituted vars still contain many H2?
$ref = new ReflectionClass($compiler);
$method = $ref->getMethod('compile');
// Re-run isolation steps manually to inspect post-isolation vars
$sliceScoped = \Omnichannel\Addons\AiPrompt\Support\WritingSectionScopeInstructions::wrapSlice(
    $slice,
    'intro',
    true,
);
$post = $baseVars;
$post['input'] = $sliceScoped;
$post['article_outline'] = $sliceScoped;
$post['outline'] = $sliceScoped;
$post['article_writing_raw_input'] = $slice;
$postH2 = [];
foreach ($post as $k => $v) {
    if (! is_string($v)) {
        continue;
    }
    $c = preg_match_all('/^##\s+/mu', $v) ?: 0;
    if ($c > 0 || in_array($k, ['input', 'outline', 'article_outline', 'article_vocabulary'], true)) {
        $postH2[$k] = ['h2' => $c, 'len' => mb_strlen($v)];
    }
}
log_ndjson('H4', 'isolation_trace.php:post_isolation_vars', 'vars_after_compiler_overwrite', $postH2);

try {
    (new WritingMultiplePassPromptIsolationGuard())->assertCompiledSectionPrompt($compiled, $unit, $fullOutline);
    log_ndjson('H5', 'isolation_trace.php:guard', 'guard_passed', ['ok' => true]);
} catch (Throwable $e) {
    $ctx = method_exists($e, 'context') ? $e->context() : [];
    log_ndjson('H5', 'isolation_trace.php:guard', 'guard_failed', [
        'message' => $e->getMessage(),
        'failure_code' => $ctx['failure_code'] ?? null,
        'section_id' => $ctx['section_id'] ?? null,
    ]);
}

echo "OK logged to debug-d04245.log\n";
echo 'parts='.count($partHeadings).' full_h2='.(preg_match_all('/^##\s+/mu', $fullOutline) ?: 0).' compiled_loose='.$compiledH2Loose."\n";
