<div class="space-y-3">
    @if ($json)
        <textarea readonly rows="28" spellcheck="false" class="block max-h-[70vh] w-full resize-y overflow-auto whitespace-pre rounded-lg border-gray-300 bg-gray-950 p-4 font-mono text-sm leading-6 text-gray-100">{{ $json }}</textarea>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">Nhấn “Gen nhanh” để tạo bản xem trước. Kết quả này không ghi đè Core Context JSON.</p>
    @endif
</div>
