<?php

declare(strict_types=1);

$logFile = __DIR__ . '/../storage/logs/laravel.log';
$lines = file($logFile);

$start = 149940;
$end = 150040;
for ($i = $start; $i <= $end && $i < count($lines); $i++) {
    echo "Line " . ($i + 1) . ": " . $lines[$i];
}
