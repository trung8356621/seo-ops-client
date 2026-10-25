<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$repo = app(App\Services\H1\Persistence\InstallationStateRepository::class);
$inst = $repo->currentInstallation();
echo "installation=" . ($inst?->installation_id ?? 'null') . PHP_EOL;
echo "enrolled=" . ($repo->isEnrolled() ? 'yes' : 'no') . PHP_EOL;
echo "revoked_at=" . json_encode($inst?->verified_snapshot['revoked_at'] ?? null) . PHP_EOL;

try {
    $result = app(App\Services\H1\Reconcile\LicenseReconcileService::class)->reconcile();
    echo "result=" . json_encode($result) . PHP_EOL;
} catch (App\Services\H1\Exceptions\H1TransportException $e) {
    echo "H1TransportException code={$e->errorCode} msg={$e->getMessage()}" . PHP_EOL;
} catch (Throwable $e) {
    echo "OtherException " . $e::class . " msg=" . $e->getMessage() . PHP_EOL;
}

$inst2 = $repo->currentInstallation();
echo "after_revoked_at=" . json_encode($inst2?->verified_snapshot['revoked_at'] ?? null) . PHP_EOL;
