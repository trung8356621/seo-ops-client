<?php

declare(strict_types=1);

$logFile = __DIR__ . '/../storage/logs/laravel.log';
$lines = file($logFile);

$inRange = false;
foreach ($lines as $idx => $line) {
    if (strpos($line, '[2026-09-12 09:14:') !== false || strpos($line, '[2026-09-12 09:15:') !== false) {
        $inRange = true;
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
