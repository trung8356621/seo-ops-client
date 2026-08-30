<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$logPath = __DIR__ . DIRECTORY_SEPARATOR . "debug-28696e.log";
$write = function (string $hid, string $msg, array $data) use ($logPath): void {
    file_put_contents($logPath, json_encode([
        "sessionId" => "28696e",
        "hypothesisId" => $hid,
        "location" => "probe",
        "message" => $msg,
        "data" => $data,
        "timestamp" => (int) round(microtime(true) * 1000),
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
};
$now = time();
$jobs = Illuminate\Support\Facades\DB::table("jobs")->where("queue", "seo")->get();
$write("H1", "seo_queue", [
    "count" => $jobs->count(),
    "jobs" => $jobs->map(function ($j) use ($now) {
        $p = json_decode((string)$j->payload, true) ?: [];
        return [
            "id" => (int)$j->id,
            "reserved_age_s" => $j->reserved_at ? ($now - (int)$j->reserved_at) : null,
            "attempts" => (int)$j->attempts,
            "name" => (string)($p["displayName"] ?? "?"),
        ];
    })->all(),
]);
app(Omnichannel\Addons\Seo\Services\SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
$run = Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun::query()->find(32);
$stepsOut = [];
foreach ($run->steps()->orderBy("id")->get() as $s) {
    $cp = is_array($s->checkpoint) ? $s->checkpoint : [];
    $m = is_array($s->metrics) ? $s->metrics : [];
    $stepsOut[] = [
        "key" => (string)$s->step_key,
        "status" => (string)$s->status,
        "a" => (int)$s->attempt_count,
        "def" => !empty($cp["deferred"]),
        "koff" => $m["keyword_batch_offset"] ?? null,
        "lp" => optional($s->last_progress_at)->toIso8601String(),
    ];
}
$write("H2", "run32", ["status" => (string)$run->status, "current" => (string)$run->current_step, "steps" => $stepsOut]);
$kw = collect($stepsOut)->firstWhere("key", "sync_provider_keywords");
$lpAge = isset($kw["lp"]) && $kw["lp"] ? ($now - strtotime($kw["lp"])) : null;
$write("H3", "freshness", ["kw" => $kw, "lp_age_s" => $lpAge, "now" => date("c", $now)]);
echo json_encode(["jobs" => $jobs->count(), "status" => $run->status, "kw" => $kw, "lp_age_s" => $lpAge], JSON_PRETTY_PRINT), PHP_EOL;
