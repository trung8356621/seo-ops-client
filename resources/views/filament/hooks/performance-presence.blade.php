@auth
<script>
(() => {
    const presenceUrl = @json(route('performance.presence'));
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value
        || '';

    const ping = () => {
        if (typeof fetch !== 'function') {
            return;
        }
        fetch(presenceUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Document-Hidden': document.hidden ? '1' : '0',
            },
            body: JSON.stringify({ document_hidden: document.hidden }),
        }).catch(() => {});
    };

    document.addEventListener('visibilitychange', () => {
        // Event-driven resume hint — not a poll loop.
        if (!document.hidden) {
            ping();
        }
    });

    document.addEventListener('livewire:init', () => {
        if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
            return;
        }
        Livewire.hook('request', ({ options }) => {
            options.headers = options.headers || {};
            options.headers['X-Document-Hidden'] = document.hidden ? '1' : '0';
        });
    });
})();
</script>
@endauth
