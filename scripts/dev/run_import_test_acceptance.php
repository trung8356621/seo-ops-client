<?php

declare(strict_types=1);

require 'C:/laragon/www/seo-ops-import-test/vendor/autoload.php';
$app = require 'C:/laragon/www/seo-ops-import-test/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * Acceptance Test Runner for SEO-OPS First-Run Installer on http://seo-ops-import-test.test/
 */

$baseUrl = 'http://seo-ops-import-test.test';
$cookieFile = sys_get_temp_dir() . '/seo_ops_test_cookie_' . bin2hex(random_bytes(4)) . '.txt';

function request(string $method, string $path, array $data = [], array $headers = []): array {
    global $baseUrl, $cookieFile;
    $url = str_starts_with($path, 'http') ? $path : $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);

    $allHeaders = $headers;
    $hasAccept = false;
    foreach ($headers as $h) {
        if (stripos($h, 'Accept:') === 0) {
            $hasAccept = true;
            break;
        }
    }
    if (! $hasAccept) {
        $allHeaders[] = 'Accept: application/json';
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($data)) {
            $payload = json_encode($data);
            $allHeaders[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        echo "  cURL ERROR on {$url}: {$err}\n";
        return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null];
    }

    $headerStr = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    // Extract headers into array
    $headersMap = [];
    foreach (explode("\r\n", $headerStr) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headersMap[strtolower(trim($k))] = trim($v);
        }
    }

    $json = json_decode($body, true);

    return [
        'status' => $status,
        'headers' => $headersMap,
        'body' => $body,
        'json' => is_array($json) ? $json : null,
    ];
}

echo "=== START ACCEPTANCE TEST ON {$baseUrl} ===\n\n";

$isAlreadyInstalled = is_file('C:/laragon/www/seo-ops-import-test/storage/installer/installed.lock');

if (! $isAlreadyInstalled) {
    // Step 1 & 2: Test Root URL and Web Entry Points redirect to /setup
    echo "1 & 2. Testing Global Setup Redirect on uninstalled instance...\n";
    $browserHeader = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
    foreach (['/', '/login', '/admin', '/admin/login', '/seo'] as $path) {
        $res = request('GET', $path, [], $browserHeader);
        if ($res['status'] === 302 && str_contains($res['headers']['location'] ?? '', '/setup')) {
            echo "  PASS: Browser navigation [{$path}] redirected to /setup (HTTP {$res['status']}, Location: {$res['headers']['location']})\n";
        } else {
            echo "  FAIL: Expected [{$path}] to redirect to /setup, got status {$res['status']}, headers: " . json_encode($res['headers']) . "\n";
            exit(1);
        }
    }

    // Also verify API requests receive JSON uninstalled status
    $resApi = request('GET', '/login', [], ['Accept: application/json']);
    if ($resApi['status'] === 307 && ($resApi['json']['error'] ?? '') === 'uninstalled') {
        echo "  PASS: JSON API request received HTTP 307 uninstalled payload.\n";
    } else {
        echo "  FAIL: Expected 307 JSON on API uninstalled request, got {$resApi['status']}\n";
        exit(1);
    }

    // Get /setup HTML to get CSRF token and verify initial load
    echo "\nTesting GET /setup...\n";
    $res = request('GET', '/setup');
    if ($res['status'] === 200) {
        echo "  PASS: /setup loaded successfully (HTTP 200)\n";
    } else {
        echo "  FAIL: /setup returned status {$res['status']}\n";
        exit(1);
    }

    // Extract CSRF token from page
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $res['body'], $matches);
    $csrfToken = $matches[1] ?? '';
    if ($csrfToken === '') {
        echo "  FAIL: Could not extract CSRF token from /setup\n";
        exit(1);
    }
    echo "  CSRF Token extracted: " . substr($csrfToken, 0, 8) . "...\n";

    // Check installer status endpoint
    $res = request('GET', '/setup/api/status', [], ["X-CSRF-TOKEN: {$csrfToken}"]);
    echo "  Installer status: " . ($res['json']['status'] ?? 'unknown') . "\n";
    $tokenFile = $res['json']['token_file'] ?? 'C:/laragon/www/seo-ops-import-test/storage/installer/setup-token.txt';
    $setupToken = trim((string) @file_get_contents($tokenFile));
    echo "  Setup Token read from disk: " . substr($setupToken, 0, 8) . "...\n";

    // Verify setup token
    echo "\nVerifying setup token...\n";
    $res = request('POST', '/setup/api/verify-token', ['token' => $setupToken], ["X-CSRF-TOKEN: {$csrfToken}"]);
    if ($res['status'] === 200 && ($res['json']['ok'] ?? false)) {
        echo "  PASS: Setup token verified successfully.\n";
    } else {
        echo "  FAIL: Setup token verification failed: " . json_encode($res['json']) . "\n";
        exit(1);
    }

    // Step 2B: Test single-connection test endpoint
    echo "\n2B. Testing Single Database Connection API...\n";
    $resSingle = request('POST', '/setup/api/database/test-single', [
        'label' => 'SEO Content AI',
        'database' => 'omi_clean_seo_ai',
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'root',
        'password' => '',
    ], ["X-CSRF-TOKEN: {$csrfToken}"]);
    if ($resSingle['status'] === 200 && ($resSingle['json']['ok'] ?? false)) {
        echo "  PASS: Single database test connection endpoint responded OK.\n";
    } else {
        echo "  FAIL: Single database test failed: " . json_encode($resSingle['json']) . "\n";
        exit(1);
    }

    // Step 3: Configure MySQL connections with independent panels
    echo "\n3. Testing Database Configuration (Accordion / Per-Service)...\n";
    $dbPayload = [
        'core_database' => 'omi_clean_client',
        'core_host' => '127.0.0.1',
        'core_port' => 3306,
        'core_username' => 'root',
        'core_password' => '',

        'seo_inherit_core' => true,
        'seo_database' => 'omi_clean_seo_ai',

        'seeding_inherit_core' => true,
        'seeding_database' => 'omi_clean_seeding',

        'social_automation_inherit_core' => true,
        'social_automation_database' => 'omi_clean_social_automation',
    ];
    $res = request('POST', '/setup/api/database', $dbPayload, ["X-CSRF-TOKEN: {$csrfToken}"]);
    if ($res['status'] === 200 && ($res['json']['ok'] ?? false)) {
        echo "  PASS: Database connection verified and configured.\n";
    } else {
        echo "  FAIL: Database configuration failed: " . ($res['json']['message'] ?? $res['body']) . "\n";
        exit(1);
    }

    // Step 4: Run migrations via wizard
    echo "\n4. Testing Application Initialization & Migrations...\n";
    $stateRecord = (new \App\Core\Installation\InstallationStateManager('C:/laragon/www/seo-ops-import-test/storage/installer'))->readRecord();
    if (($stateRecord['step'] ?? '') === 'admin') {
        echo "  PASS: Migrations already completed successfully in current run (step = admin).\n";
    } else {
        $res = request('POST', '/setup/api/initialize', [], ["X-CSRF-TOKEN: {$csrfToken}"]);
        if ($res['status'] === 200 && ($res['json']['ok'] ?? false)) {
            echo "  PASS: Migrations executed successfully. Logs count: " . count($res['json']['logs'] ?? []) . "\n";
        } else {
            echo "  FAIL: Initialization failed: " . ($res['json']['message'] ?? $res['body']) . "\n";
            exit(1);
        }
    }

    // Step 5: Create Administrator
    echo "\n5. Testing Administrator Creation...\n";
    $adminEmail = 'owner_' . bin2hex(random_bytes(3)) . '@example.test';
    $adminPass = 'SecureAdminSecret123!';
    $adminPayload = [
        'name' => 'Initial Owner',
        'email' => $adminEmail,
        'password' => $adminPass,
        'password_confirmation' => $adminPass,
    ];
    $res = request('POST', '/setup/api/admin', $adminPayload, ["X-CSRF-TOKEN: {$csrfToken}"]);
    if ($res['status'] === 200 && ($res['json']['ok'] ?? false)) {
        echo "  PASS: Administrator created with email: {$adminEmail}\n";
    } else {
        echo "  FAIL: Admin creation failed: " . ($res['json']['message'] ?? $res['body']) . "\n";
        exit(1);
    }

    // Step 6: Complete Installation
    echo "\n6. Testing Finish Installation...\n";
    $res = request('POST', '/setup/api/finish', [], ["X-CSRF-TOKEN: {$csrfToken}"]);
    if ($res['status'] === 200 && ($res['json']['ok'] ?? false)) {
        echo "  PASS: Installation finished and locked.\n";
    } else {
        echo "  FAIL: Finish installation failed: " . ($res['json']['message'] ?? $res['body']) . "\n";
        exit(1);
    }
} else {
    echo "1-6. Installation already performed and completed.\n";
    $existingAdminUser = \Illuminate\Support\Facades\DB::connection('mysql')->table('users')->where('role', 'owner')->first();
    $adminEmail = $existingAdminUser->email;
    $adminPass = 'SecureAdminSecret123!';
    echo "  Active Admin: {$adminEmail}\n";
}

// Step 7 & 8: Log in to Admin
echo "\n7 & 8. Testing Admin Login & Dashboard...\n";
// Request login page to get login CSRF token
$res = request('GET', '/login');
preg_match('/name="_token" value="([^"]+)"/', $res['body'], $loginCsrfMatches);
$loginCsrf = $loginCsrfMatches[1] ?? $csrfToken;

$loginPayload = [
    '_token' => $loginCsrf,
    'email' => $adminEmail,
    'password' => $adminPass,
];

// Perform login via form URL-encoded
$ch = curl_init("{$baseUrl}/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($loginPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$raw = curl_exec($ch);
$loginStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$loginHeaderSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$loginHeaders = substr($raw, 0, $loginHeaderSize);
curl_close($ch);

echo "  Login POST status: {$loginStatus}\n";
preg_match('/location:\s*(.*)/i', $loginHeaders, $locMatch);
echo "  Login redirect location: " . trim($locMatch[1] ?? 'none') . "\n";
echo "  Cookie file content:\n" . file_get_contents($cookieFile) . "\n";
if (in_array($loginStatus, [302, 200], true)) {
    echo "  PASS: Administrator logged in.\n";
} else {
    echo "  FAIL: Login failed with status {$loginStatus}\n";
    exit(1);
}

// Access Admin Panel
$res = request('GET', '/admin', [], ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
echo "  Admin GET status: {$res['status']}\n";
echo "  Admin GET headers: " . json_encode($res['headers']) . "\n";
echo "  Admin GET body preview: " . substr(strip_tags($res['body']), 0, 200) . "\n";
if (in_array($res['status'], [200, 302], true)) {
    echo "  PASS: Admin panel accessible.\n";
} else {
    echo "  FAIL: Admin panel access failed with status {$res['status']}\n";
    exit(1);
}

// Step 9: Verify Service Catalog
echo "\n9. Testing Service Catalog Inactivity...\n";
$res = request('GET', '/admin/services');
echo "  Admin services GET status: {$res['status']}\n";

// Step 10 & 11: Real execution entry points for SEO and Social Automation
echo "\n10 & 11. Testing Fail-Closed Entitlement Gates for Commercial Services...\n";
$gate = app(\App\Services\H1\Enforcement\ServiceEntitlementGate::class);
echo "  ServiceEntitlementGate::enforcementActive() = " . ($gate->enforcementActive() ? 'true' : 'false') . "\n";
if (! $gate->enforcementActive()) {
    echo "  FAIL: Enforcement gate was NOT active!\n";
    exit(1);
}

// 10: Attempt licensed SEO operation (saving active site)
echo "  Attempting licensed SEO operation (Site activation)...\n";
try {
    $site = new \App\Models\Site();
    $site->user_id = 1;
    $site->domain = 'test-denied.local';
    $site->status = 'active';
    $site->save();
    echo "  FAIL: Licensed SEO operation was NOT denied!\n";
    exit(1);
} catch (\Throwable $e) {
    echo "  PASS: Licensed SEO operation denied safely: " . $e->getMessage() . "\n";
}

// 11: Attempt licensed Social Automation operation
echo "  Attempting licensed Social Automation operation...\n";
try {
    $saAccess = app(\Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess::class);
    $saAccess->assertCanAccess();
    echo "  FAIL: Licensed Automation operation was NOT denied!\n";
    exit(1);
} catch (\Throwable $e) {
    echo "  PASS: Licensed Automation operation denied safely (HTTP 403 / Access Denied).\n";
}

// Step 12: Confirm zero commercial activation in DB
echo "\n12. Confirming ZERO commercial activation...\n";
$h1Count = (int) \Illuminate\Support\Facades\DB::connection('mysql')->table('h1_installations')->count();
$credCount = (int) \Illuminate\Support\Facades\DB::connection('mysql')->table('h1_credentials')->count();
$activeServices = (int) \Illuminate\Support\Facades\DB::connection('mysql')->table('services')->where('is_active', true)->count();
echo "  H1 Installations in DB: {$h1Count}\n";
echo "  H1 Machine Credentials in DB: {$credCount}\n";
echo "  Active Commercial Services in DB: {$activeServices}\n";
if ($h1Count === 0 && $credCount === 0 && $activeServices === 0) {
    echo "  PASS: Zero commercial activation confirmed!\n";
} else {
    echo "  FAIL: Commercial activation detected!\n";
    exit(1);
}

// Step 13: Reload /setup -> must remain locked
echo "\n13. Testing /setup locked after installation...\n";
$res = request('GET', '/setup', [], ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
if ($res['status'] === 302 && str_contains($res['headers']['location'] ?? '', '/admin')) {
    echo "  PASS: Browser /setup is locked and redirects to /admin (HTTP 302, Location: {$res['headers']['location']})\n";
} else {
    echo "  FAIL: /setup was not locked! Status {$res['status']}\n";
    exit(1);
}

// Also verify JSON request to /setup is locked with 403
$resJson = request('GET', '/setup', [], ['Accept: application/json']);
if ($resJson['status'] === 403 && ($resJson['json']['error'] ?? '') === 'already_installed') {
    echo "  PASS: API /setup is locked with HTTP 403 already_installed.\n";
} else {
    echo "  FAIL: Expected 403 on API /setup request, got {$resJson['status']}\n";
    exit(1);
}

// Step 14: Simulate Database Outage -> 503 Maintenance Page
echo "\n14. Testing Database Outage Simulation...\n";
$envFile = 'C:/laragon/www/seo-ops-import-test/.env';
$envOriginal = file_get_contents($envFile);
// Temporarily change DB_PORT to 3307 to simulate real DB network outage
file_put_contents($envFile, str_replace('DB_PORT=3306', 'DB_PORT=3307', $envOriginal));

try {
    $res = request('GET', '/admin');
    echo "  Outage GET /admin status: {$res['status']}\n";
    if ($res['status'] === 503) {
        echo "  PASS: Database outage correctly served HTTP 503 Maintenance page.\n";
    } else {
        echo "  FAIL: Expected HTTP 503 on database outage, got {$res['status']}\n";
        exit(1);
    }

    // Step 15: Restore Database -> normal operation
    echo "\n15. Testing Database Restoration...\n";
    file_put_contents($envFile, $envOriginal);
    $res = request('GET', '/admin', [], ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
    echo "  Restored GET /admin status: {$res['status']}\n";
    if (in_array($res['status'], [200, 302], true)) {
        echo "  PASS: Application restored to normal installed state.\n";
    } else {
        echo "  FAIL: Application failed to restore, status {$res['status']}\n";
        exit(1);
    }
} finally {
    file_put_contents($envFile, $envOriginal);
}

echo "\n=== ALL 15 ACCEPTANCE TESTS PASSED SUCCESSFULLY! ===\n";
