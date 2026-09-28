@php
    $helpRouteName = optional(request()->route())->getName();
    $helpPanelId = null;
    try {
        $helpPanelId = \Filament\Facades\Filament::getCurrentPanel()?->getId();
    } catch (\Throwable) {
        $helpPanelId = null;
    }
@endphp

<div class="global-help-topbar-host" data-help-trigger-host>
    <button
        type="button"
        id="global-help-trigger"
        class="global-help-trigger"
        data-help-trigger
        title="{{ __('help.trigger') }}"
        aria-label="{{ __('help.trigger_aria') }}"
        aria-haspopup="dialog"
        aria-controls="global-help-drawer"
        x-data
        x-bind:aria-expanded="$store.help && $store.help.isOpen ? 'true' : 'false'"
        x-on:click="
            if (window.Alpine?.store('help')) {
                Alpine.store('help').open({ trigger: $el });
            } else {
                window.dispatchEvent(new CustomEvent('seo-global-help:open', { detail: { trigger: $el } }));
            }
        "
    >
        <svg class="global-help-trigger__icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
        </svg>
        <span class="global-help-trigger__label">{{ __('help.trigger') }}</span>
    </button>
</div>

<script>
    window.__SEO_HELP_ROUTE_NAME__ = @js($helpRouteName);
    window.__HELP_PANEL_ID__ = @js($helpPanelId);
    document.body?.setAttribute?.('data-help-route-name', @js((string) $helpRouteName));
    document.body?.setAttribute?.('data-help-panel', @js((string) $helpPanelId));
</script>
