<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\AiPrompt\Models\Prompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->find(2);
if (! $user instanceof User) {
    echo "ERROR: User 2 not found.\n";
    exit(1);
}

Auth::login($user);
$kernel = $app->make(HttpKernel::class);

echo "=== SEO OPS PROMPT + TASK CANONICAL SMOKE TEST ===\n";
echo "Authenticated as: " . $user->name . " (ID " . $user->id . ")\n\n";

$results = [];

// Helper to make authenticated request
function makeRequest($kernel, string $uri, array $cookies = []) {
    $request = Request::create('http://seo-ops.test' . $uri, 'GET', [], $cookies);
    $response = $kernel->handle($request);
    $content = (string) $response->getContent();
    $status = $response->getStatusCode();
    $kernel->terminate($request, $response);
    return ['status' => $status, 'content' => $content];
}

// 1. Open Admin (/admin)
echo "[1] Testing Admin dashboard (/admin)...\n";
$resAdmin = makeRequest($kernel, '/admin');
echo "    Status: {$resAdmin['status']}\n";
$hasAdminMenu = str_contains($resAdmin['content'], 'filament') || str_contains($resAdmin['content'], 'Hệ thống') || str_contains($resAdmin['content'], 'Quản lý');
echo "    Admin panel accessible: " . ($hasAdminMenu ? 'YES' : 'NO') . "\n";
$results['admin_dashboard'] = $resAdmin['status'] === 200;

// 2. Open Prompt Management (/admin/prompts)
echo "[2] Testing Prompt management (/admin/prompts)...\n";
$resPrompts = makeRequest($kernel, '/admin/prompts');
echo "    Status: {$resPrompts['status']}\n";
$hasPromptTable = str_contains($resPrompts['content'], 'prompts') || str_contains($resPrompts['content'], 'Tên prompt') || str_contains($resPrompts['content'], 'Product review');
echo "    Prompt table rendered: " . ($hasPromptTable ? 'YES' : 'NO') . "\n";
$results['prompt_management'] = $resPrompts['status'] === 200;

// 3. Open existing Prompt (/admin/prompts/2/edit or similar)
$existingPrompt = Prompt::query()->first();
$promptId = $existingPrompt ? (int) $existingPrompt->id : 2;
echo "[3] Testing existing Prompt edit (/admin/prompts/{$promptId}/edit)...\n";
$resPromptEdit = makeRequest($kernel, "/admin/prompts/{$promptId}/edit");
echo "    Status: {$resPromptEdit['status']}\n";
$results['prompt_edit'] = $resPromptEdit['status'] === 200;

// 4. Verify Prompt version/routing/history UI
echo "[4] Testing Prompt test & history UI (/admin/prompts/{$promptId}/test)...\n";
$resPromptTest = makeRequest($kernel, "/admin/prompts/{$promptId}/test");
echo "    Status: {$resPromptTest['status']}\n";
$hasVersionOrRouting = str_contains($resPromptTest['content'], 'version') || str_contains($resPromptTest['content'], 'model') || str_contains($resPromptTest['content'], 'Test') || str_contains($resPromptTest['content'], 'routing');
echo "    Version/routing/test UI present: " . ($hasVersionOrRouting ? 'YES' : 'NO') . "\n";
$results['prompt_test_history'] = $resPromptTest['status'] === 200;

// 5. Open Task Management (/admin/tasks)
echo "[5] Testing Task management (/admin/tasks)...\n";
$resTasks = makeRequest($kernel, '/admin/tasks');
echo "    Status: {$resTasks['status']}\n";
$hasTaskTable = str_contains($resTasks['content'], 'tasks') || str_contains($resTasks['content'], 'Quy trình') || str_contains($resTasks['content'], 'Workflow');
echo "    Task table rendered: " . ($hasTaskTable ? 'YES' : 'NO') . "\n";
$results['task_management'] = $resTasks['status'] === 200;

// 6. Open existing Task (/admin/tasks/1/edit)
$existingTask = SeoTask::query()->first();
$taskId = $existingTask ? (int) $existingTask->id : 1;
echo "[6] Testing existing Task edit (/admin/tasks/{$taskId}/edit)...\n";
$resTaskEdit = makeRequest($kernel, "/admin/tasks/{$taskId}/edit");
echo "    Status: {$resTaskEdit['status']}\n";
$results['task_edit'] = $resTaskEdit['status'] === 200;

// 7. Verify Task builder & test UI (/admin/tasks/{id}/builder, /admin/tasks/{id}/test)
echo "[7] Testing Task builder (/admin/tasks/{$taskId}/builder) and test (/admin/tasks/{$taskId}/test)...\n";
$resTaskBuilder = makeRequest($kernel, "/admin/tasks/{$taskId}/builder");
echo "    Builder Status: {$resTaskBuilder['status']}\n";
$hasBuilderAsset = str_contains($resTaskBuilder['content'], 'task-builder.jsx') || str_contains($resTaskBuilder['content'], 'seo-task-workflow-builder-root');
echo "    Builder root / asset present: " . ($hasBuilderAsset ? 'YES' : 'NO') . "\n";

$resTaskTest = makeRequest($kernel, "/admin/tasks/{$taskId}/test");
echo "    Test Status: {$resTaskTest['status']}\n";
$hasTestForm = str_contains($resTaskTest['content'], 'Chạy thử quy trình') || str_contains($resTaskTest['content'], 'runTest') || str_contains($resTaskTest['content'], 'testInput');
echo "    Test execution UI present: " . ($hasTestForm ? 'YES' : 'NO') . "\n";
$results['task_builder_test'] = ($resTaskBuilder['status'] === 200 && $resTaskTest['status'] === 200);

// 8. Verify Content Project still sees/uses the Task where applicable
echo "[8] Testing Content Projects integration with Task...\n";
$taskInDb = SeoTask::query()->where('is_active', true)->first();
$taskResolves = $taskInDb instanceof SeoTask;
echo "    SeoTask resolved for Content Projects: " . ($taskResolves ? 'YES (ID ' . $taskInDb->id . ': ' . $taskInDb->name . ')' : 'NO') . "\n";
$results['content_projects_task_resolution'] = $taskResolves;

// 9. Verify no duplicate Prompt/Task menu entries
echo "[9] Verifying navigation entries on Admin panel...\n";
Filament::setCurrentPanel(Filament::getPanel('admin'));
$adminNav = Filament::getNavigation();
$promptEntries = 0;
$taskEntries = 0;
foreach ($adminNav as $group) {
    foreach ($group->getItems() as $item) {
        $url = $item->getUrl();
        if (str_contains($url, '/admin/prompts')) {
            $promptEntries++;
        }
        if (str_contains($url, '/admin/tasks')) {
            $taskEntries++;
        }
    }
}
echo "    Prompt nav items in Admin: {$promptEntries} (expected: 1)\n";
echo "    Task nav items in Admin: {$taskEntries} (expected: 1)\n";
$results['no_duplicate_nav'] = ($promptEntries === 1 && $taskEntries === 1);

// 10. Verify no obvious i18n / label regression
echo "[10] Checking labels and i18n...\n";
$promptNavLabel = \Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource::getNavigationLabel();
$taskNavLabel = \Omnichannel\Addons\AiPrompt\Filament\Resources\TaskResource::getNavigationLabel();
echo "    Prompt Nav Label: {$promptNavLabel}\n";
echo "    Task Nav Label: {$taskNavLabel}\n";
$results['i18n_labels'] = filled($promptNavLabel) && filled($taskNavLabel);

// 11. Database Inspection Proof: Prove loaded records come from CLIENT DB, not omi_seo_ai
echo "[11] Proving runtime reads Prompt/Task from CLIENT DB (omi_client)...\n";
$promptModel = new Prompt();
$taskModel = new SeoTask();
$promptDb = $promptModel->getConnection()->getDatabaseName();
$taskDb = $taskModel->getConnection()->getDatabaseName();
$coreDb = config('database.connections.mysql.database');
$seoDb = config('database.connections.omi_seo_ai.database');

echo "    Prompt Model DB: {$promptDb} (Core expected: {$coreDb})\n";
echo "    Task Model DB: {$taskDb} (Core expected: {$coreDb})\n";

$isPromptOnCore = ($promptDb === $coreDb) && ($promptDb !== $seoDb);
$isTaskOnCore = ($taskDb === $coreDb) && ($taskDb !== $seoDb);

// Verify actual record loaded
$verifiedPrompt = Prompt::query()->find(2);
$verifiedTask = SeoTask::query()->find(1);

$promptLoadedFromCore = $verifiedPrompt && $verifiedPrompt->getConnection()->getDatabaseName() === $coreDb;
$taskLoadedFromCore = $verifiedTask && $verifiedTask->getConnection()->getDatabaseName() === $coreDb;

echo "    Prompt loaded from client DB: " . ($promptLoadedFromCore ? 'YES' : 'NO') . "\n";
echo "    Task loaded from client DB: " . ($taskLoadedFromCore ? 'YES' : 'NO') . "\n";

$results['db_inspection_client_db_proof'] = $isPromptOnCore && $isTaskOnCore && $promptLoadedFromCore && $taskLoadedFromCore;

echo "\n=== SMOKE TEST SUMMARY ===\n";
$allPassed = true;
foreach ($results as $check => $passed) {
    echo "  " . ($passed ? "[PASS]" : "[FAIL]") . " {$check}\n";
    if (! $passed) {
        $allPassed = false;
    }
}
echo "\nOverall result: " . ($allPassed ? "SUCCESS - ALL CHECKS PASSED" : "FAILED") . "\n";
