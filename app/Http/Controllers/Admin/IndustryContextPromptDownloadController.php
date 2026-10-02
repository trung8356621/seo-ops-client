<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\IndustryContext\IndustryContextProfileManager;
use App\Models\IndustryContextProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\IndustryContextGenerationService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class IndustryContextPromptDownloadController extends Controller
{
    public function create(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);
        $seed = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'in:vi,en'],
            'market' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $prompt = app(IndustryContextGenerationService::class)->compilePrompt(
            (string) $seed['name'],
            (string) ($seed['language'] ?? 'vi'),
            $seed['market'] ?? null,
            $seed['notes'] ?? null,
        );

        return $this->download($prompt, (Str::slug((string) $seed['name']) ?: 'industry-context').'-core-prompt.txt');
    }

    public function existing(
        Request $request,
        string $key,
        string $type,
    ): StreamedResponse {
        $this->authorizeAdmin($request);
        abort_unless(in_array($type, [
            IndustryContextProfile::TYPE_CORE,
            IndustryContextProfile::TYPE_DISCOVERY,
            IndustryContextProfile::TYPE_BREAKOUT,
            IndustryContextProfile::TYPE_MATCH,
        ], true), 404);

        $seed = $request->validate([
            'language' => ['nullable', 'string', 'in:vi,en'],
            'market' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $core = app(IndustryContextProfileManager::class)->active($key, IndustryContextProfile::TYPE_CORE);
        abort_if($core === null, 422, 'Industry Context này không có Core đang hoạt động.');
        $identity = (array) ($core->context_json['identity'] ?? []);
        $storedMarket = implode(', ', array_map('strval', (array) ($identity['market'] ?? [])));
        $prompt = app(IndustryContextGenerationService::class)->compilePromptForType(
            $type,
            (string) ($identity['context_name'] ?? $core->name),
            (string) ($seed['language'] ?? $identity['language'] ?? 'en'),
            $seed['market'] ?? ($storedMarket !== '' ? $storedMarket : null),
            $type === IndustryContextProfile::TYPE_CORE ? null : (array) $core->context_json,
            $seed['notes'] ?? null,
        );

        return $this->download($prompt, $key.'-'.$type.'-prompt.txt');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(in_array((string) $request->user()?->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true), 403);
    }

    private function download(string $prompt, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($prompt): void {
                echo $prompt;
            },
            $filename,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
