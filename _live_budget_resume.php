<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
    ->bootstrapLegacySharedConnection();

use App\Models\User;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Illuminate\Support\Facades\DB;

auth()->login(User::query()->findOrFail(2));
$bus = app(ContentProjectCommandBus::class);
$result = $bus->dispatch(new ResumeProjectItemFromFailedStepCommand(
    projectRef: 904,
    itemRefs: [8856],
    mode: 'full',
    settings: [
        AiCostPolicy::SETTING_KEY => AiCostPolicy::FreeOnly->value,
        'ai_generation_mode' => AiCostPolicy::FreeOnly->value,
    ],
), ActorContext::user(2));

echo 'success='.json_encode($result->success).' msg='.$result->message.PHP_EOL;
echo 'meta='.json_encode($result->metadata, JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'pending_jobs='.DB::table('jobs')->where('queue', 'seo-content-run')->count().PHP_EOL;
