<?php

declare(strict_types=1);

$logFile = __DIR__ . '/../storage/logs/laravel.log';
$lines = file($logFile);

echo "Searching for 1791 or 536 or Run 207...\n";
foreach ($lines as $idx => $line) {
    if (strpos($line, '1791') !== false || strpos($line, '536') !== false || strpos($line, '"run_id":207') !== false || strpos($line, '"run_id":"207"') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
