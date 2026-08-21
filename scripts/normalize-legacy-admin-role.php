<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->find(1);
if ($user === null) {
    fwrite(STDERR, "user 1 missing\n");
    exit(1);
}

echo 'before='.$user->role.PHP_EOL;
echo 'owners='.User::query()->where('role', 'owner')->count().PHP_EOL;

if ($user->role === 'admin') {
    // Installation primary account (id=1, parent_id null) — normalize to owner, not staff.
    $user->role = User::ROLE_OWNER;
    $user->save();
}

echo 'after='.$user->fresh()->role.PHP_EOL;
