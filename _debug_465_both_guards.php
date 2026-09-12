<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePromptIsolationGuard;
use Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner;
use Omnichannel\Addons\AiPrompt\Services\WritingSectionPromptCompiler;
use Omnichannel\Addons\AiPrompt\Support\WritingMultiplePassPromptIsolationGuard;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;

Auth::loginUsingId(2);
$rec = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id')->first();
app(SeoDatabaseConnectionService::class)->bootstrapFromConnection($rec);

$full = trim((string) PromptResult::query()->findOrFail(1448)->output_text);
$vocab = trim((string) PromptResult::query()->findOrFail(1449)->output_text);
$prompt = SeoPrompt::query()->findOrFail(5);
$plan = (new WritingMultiplePassStepPlanner())->planFromRows(
    (new OutlineStructuredRowsNormalizer())->normalize($full),
);
$unit = $plan->units[0];
$compiled = app(WritingSectionPromptCompiler::class)->compile($prompt, [
    'article_outline' => $full,
    'outline' => $full,
    'input' => $full,
    'article_vocabulary' => $vocab,
    'article_length' => '2000',
    'generation_shape' => 'sectioned',
    'language' => 'vi',
    'keyword' => 'Effortless Chic',
], $unit);

try {
    (new WritingMultiplePassPromptIsolationGuard())->assertCompiledSectionPrompt($compiled, $unit, $full);
    echo "writing_guard=OK\n";
} catch (Throwable $e) {
    echo 'writing_guard=FAIL '.$e->getMessage()."\n";
}
try {
    (new SectionedFreePromptIsolationGuard())->assertSectionPromptIsIsolated($compiled, ['section_id' => $unit->sectionId]);
    echo "free_guard=OK\n";
} catch (Throwable $e) {
    echo 'free_guard=FAIL '.$e->getMessage()."\n";
}
foreach (SectionedFreePromptIsolationGuard::forbiddenMarkers() as $m) {
    if (str_contains($compiled, $m)) {
        echo "STILL_HAS $m\n";
    }
}
echo 'compiled_len='.mb_strlen($compiled)."\n";
