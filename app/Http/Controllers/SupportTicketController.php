<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Services\SupportTickets\SupportTicketSubmitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketSubmitService $submitter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) auth()->id();
        if ($userId <= 0) {
            return response()->json(['message' => __('support_ticket.errors.unauthenticated')], 401);
        }

        $rows = SupportTicket::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (SupportTicket $ticket): array => $this->submitter->serialize($ticket))
            ->values()
            ->all();

        return response()->json([
            'tickets' => $rows,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = (int) auth()->id();
        if ($userId <= 0) {
            return response()->json(['message' => __('support_ticket.errors.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'page_url' => ['nullable', 'string', 'max:2000'],
            'route_name' => ['nullable', 'string', 'max:200'],
            'service' => ['nullable', 'string', 'max:32'],
            'connection_hash' => ['nullable', 'string', 'max:64'],
            'file' => ['nullable', 'file'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file'],
        ]);

        try {
            $result = $this->submitter->submit(
                userId: $userId,
                title: (string) $validated['title'],
                body: (string) $validated['body'],
                request: $request,
                files: $this->collectFiles($request),
                pageUrl: $validated['page_url'] ?? null,
                routeName: $validated['route_name'] ?? null,
                service: $validated['service'] ?? null,
                connectionHash: $validated['connection_hash'] ?? null,
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first()
                    ?? __('support_ticket.errors.invalid'),
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'ticket' => $this->submitter->serialize($result['ticket']),
            'message' => $result['message'],
        ], 201);
    }

    /**
     * @return list<UploadedFile>
     */
    private function collectFiles(Request $request): array
    {
        $files = [];
        $single = $request->file('file');
        if ($single instanceof UploadedFile) {
            $files[] = $single;
        }
        $multi = $request->file('files');
        if (is_array($multi)) {
            foreach ($multi as $item) {
                if ($item instanceof UploadedFile) {
                    $files[] = $item;
                }
            }
        }

        return $files;
    }
}
