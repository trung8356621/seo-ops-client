<?php

declare(strict_types=1);

namespace App\Http\Controllers\Control;

use App\Control\Commands\ControlCommandReceiptService;
use App\Control\Exceptions\ControlAuthenticationException;
use App\Control\Signing\ControlCommandAuthenticator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControlCommandController extends Controller
{
    public function __construct(
        private readonly ControlCommandAuthenticator $authenticator,
        private readonly ControlCommandReceiptService $receipts,
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $envelope = $this->authenticator->authenticate($request);
        } catch (ControlAuthenticationException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->codeKey,
                'message' => $e->getMessage(),
            ], $e->status);
        }

        $outcome = $this->receipts->handle($envelope);

        return response()->json([
            'ok' => $outcome->error === null,
            'command_id' => $envelope->commandId,
            'status' => $outcome->status->value,
            'result' => $outcome->result,
            'error' => $outcome->error,
        ], $outcome->httpStatus());
    }
}
