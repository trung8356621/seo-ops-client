@php
    /** @var \App\Models\SupportTicket|null $ticket */
    $ticket = $getRecord();
    $attachments = is_array($ticket?->metadata['attachments'] ?? null)
        ? $ticket->metadata['attachments']
        : [];
@endphp

@if ($attachments !== [])
    <div class="flex flex-wrap gap-3">
        @foreach ($attachments as $row)
            @php
                $url = (string) ($row['url'] ?? '');
                $name = (string) ($row['name'] ?? 'attachment');
                $isImage = (bool) ($row['is_image'] ?? false);
            @endphp
            @if ($url !== '')
                <a href="{{ $url }}" target="_blank" rel="noopener" class="block max-w-xs rounded border border-gray-200 p-2 text-sm dark:border-gray-700">
                    @if ($isImage)
                        <img src="{{ $url }}" alt="{{ $name }}" class="mb-1 max-h-36 rounded object-contain" />
                    @endif
                    <span class="text-primary-600 underline">{{ $name }}</span>
                </a>
            @endif
        @endforeach
    </div>
@endif
