<?php

declare(strict_types=1);

$logFile = __DIR__ . '/../storage/logs/web-app-2026-09-12.log';
if (!file_exists($logFile)) {
    echo "web-app-2026-09-12.log not found\n";
    exit;
}

$lines = file($logFile);
$total = count($lines);
echo "Total lines in web-app-2026-09-12.log: $total\n";

$start = max(0, $total - 100);
for ($i = $start; $i < $total; $i++) {
    echo "Line " . ($i + 1) . ": " . substr(trim($lines[$i]), 0, 300) . "\n";
}
