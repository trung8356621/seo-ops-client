<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Workspace\WorkspaceDestinationRegistry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Neutral authenticated Access Hub — launcher only, not an admin panel.
 */
final class WorkspaceHubController extends Controller
{
    public function __invoke(Request $request, WorkspaceDestinationRegistry $registry): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('workspace.hub', [
            'user' => $user,
            'destinations' => $registry->visibleFor($user),
        ]);
    }
}
