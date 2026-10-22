<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Core\Installation\AdminAccountCreator;
use App\Core\Installation\AppInitializer;
use App\Core\Installation\DatabaseConfigurator;
use App\Core\Installation\InstallationState;
use App\Core\Installation\InstallationStateManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class SetupWizardController extends Controller
{
    public function __construct(
        private readonly InstallationStateManager $stateManager,
        private readonly DatabaseConfigurator $dbConfigurator,
        private readonly AppInitializer $appInitializer,
        private readonly AdminAccountCreator $adminCreator,
    ) {}

    public function index(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        $status = $this->stateManager->currentStatus();
        if ($status === InstallationState::INSTALLED) {
            return redirect('/admin');
        }

        $token = $this->stateManager->getOrCreateSetupToken();
        $record = $this->stateManager->readRecord();

        // Local environment owner possession: if requesting from localhost/127.0.0.1, auto-authorize session
        $isLocal = in_array($request->ip(), ['127.0.0.1', '::1'], true);
        if ($isLocal) {
            $request->session()->put('setup_authorized', true);
        }

        return view('setup.wizard', [
            'status' => $status,
            'currentStep' => $record['step'] ?? 'database',
            'token' => $token,
            'tokenPath' => $this->stateManager->tokenFilePath(),
            'isAuthorized' => (bool) $request->session()->get('setup_authorized', false),
            'record' => $record,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $status = $this->stateManager->currentStatus();
        $record = $this->stateManager->readRecord();
        $isAuthorized = (bool) $request->session()->get('setup_authorized', false);

        return response()->json([
            'status' => $status,
            'step' => $record['step'] ?? 'database',
            'databases' => $record['databases'] ?? null,
            'authorized' => $isAuthorized,
            'token_file' => $this->stateManager->tokenFilePath(),
        ]);
    }

    public function verifyToken(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        if ($this->stateManager->verifySetupToken($token)) {
            $request->session()->put('setup_authorized', true);

            return response()->json([
                'ok' => true,
                'message' => 'Setup token verified successfully.',
            ]);
        }

        return response()->json([
            'ok' => false,
            'message' => 'Invalid setup token. Please check the token in '.basename($this->stateManager->tokenFilePath()),
        ], 403);
    }

    public function testSingleDatabase(Request $request): JsonResponse
    {
        $this->assertAuthorized($request);

        $result = $this->dbConfigurator->testSingleConnection($request->all());

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function configureDatabase(Request $request): JsonResponse
    {
        $this->assertAuthorized($request);

        return $this->stateManager->withLock(function () use ($request): JsonResponse {
            $result = $this->dbConfigurator->testAndConfigure($request->all());

            return response()->json($result, $result['ok'] ? 200 : 422);
        });
    }

    public function initializeApp(Request $request): JsonResponse
    {
        $this->assertAuthorized($request);

        return $this->stateManager->withLock(function (): JsonResponse {
            $logs = [];
            ob_start();
            try {
                $result = $this->appInitializer->initialize(function (string $line) use (&$logs): void {
                    $logs[] = $line;
                });
            } finally {
                $buffered = ob_get_clean();
                if (is_string($buffered) && trim($buffered) !== '') {
                    $logs[] = trim($buffered);
                }
            }

            return response()->json([
                'ok' => $result['ok'],
                'message' => $result['message'],
                'logs' => $logs,
                'steps' => $result['steps'] ?? [],
            ], $result['ok'] ? 200 : 500);
        });
    }

    public function createAdmin(Request $request): JsonResponse
    {
        $this->assertAuthorized($request);

        return $this->stateManager->withLock(function () use ($request): JsonResponse {
            $result = $this->adminCreator->createAdmin($request->all());

            return response()->json($result, $result['ok'] ? 200 : 422);
        });
    }

    public function finish(Request $request): JsonResponse
    {
        $this->assertAuthorized($request);

        return $this->stateManager->withLock(function () use ($request): JsonResponse {
            $this->stateManager->markInstalled();
            $request->session()->forget('setup_authorized');

            return response()->json([
                'ok' => true,
                'message' => 'Installation completed successfully.',
                'redirect' => url('/admin'),
            ]);
        });
    }

    private function assertAuthorized(Request $request): void
    {
        if ($this->stateManager->hasInstalledLock()) {
            abort(403, 'Application is already installed.');
        }

        $isLocal = in_array($request->ip(), ['127.0.0.1', '::1'], true);
        if (! $isLocal && ! $request->session()->get('setup_authorized', false)) {
            abort(403, 'Setup session unauthorized. Enter setup token first.');
        }
    }
}
