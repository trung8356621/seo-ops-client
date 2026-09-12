<?php

declare(strict_types=1);

$logFile = __DIR__ . '/../storage/logs/laravel.log';
$lines = file($logFile);
$total = count($lines);
echo "Total lines: $total\n";
for ($i = 150041; $i < $total; $i++) {
    echo "Line " . ($i + 1) . ": " . $lines[$i];
}
