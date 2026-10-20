<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Performance\ActiveUserPresence;
use App\Core\Performance\BackgroundHeavyPolicy;
use App\Core\Performance\PerformanceSettings;
use App\Core\Performance\RequestPageVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Explicit visibility ping — event-driven (not a poll storm).
 * Hidden → no cancel of running jobs; Visible → refresh presence so deferred work can resume.
 */
final class PerformancePresenceController extends Controller
{
    public function __invoke(
        Request $request,
        ActiveUserPresence $presence,
        BackgroundHeavyPolicy $background,
        PerformanceSettings $settings,
    ): JsonResponse {
        $userId = (int) ($request->user()?->getAuthIdentifier() ?? 0);
        abort_unless($userId > 0, 401);

        $hidden = RequestPageVisibility::isHidden($request);
        // Always refresh presence for this account (tabs coalesce to one user).
        $presence->ping($userId);

        return response()->json([
            'ok' => true,
            'hidden' => $hidden,
            'active_users' => $presence->count(),
            'allows_optional_background' => $background->allowsOptionalBackground(),
            'preset' => $settings->snapshot(),
        ]);
    }
}
