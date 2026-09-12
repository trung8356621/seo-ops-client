<?php

declare(strict_types=1);

$logFile = __DIR__ . '/../storage/logs/web-app-2026-09-12.log';
$lines = file($logFile);

for ($i = 637; $i <= 645; $i++) {
    echo "=== LINE " . ($i + 1) . " ===\n";
    echo $lines[$i] . "\n\n";
}
