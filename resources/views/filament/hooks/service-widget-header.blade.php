@php
    // Relative path only — absolute APP_URL (http) on an https page causes
    // POST→302→GET and MethodNotAllowed on /api/service-widget/session.
    $sessionUrl = route('service-widget.session', absolute: false);
@endphp

@vite([
    'resources/js/service-widget/headerServiceWidget.js',
    'resources/css/service-widget-header.css',
], 'build-support')

<div
    class="service-widget-header"
    data-service-widget-header
    data-session-url="{{ $sessionUrl }}"
>
    <button
        type="button"
        class="service-widget-header__trigger"
        data-service-widget-trigger
        title="Dịch vụ"
        aria-label="Dịch vụ"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="service-widget-dropdown"
    >
        <svg class="service-widget-header__icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
        </svg>
        <span class="service-widget-header__label">Dịch vụ</span>
    </button>

    <div
        id="service-widget-dropdown"
        class="service-widget-header__dropdown"
        data-service-widget-dropdown
        hidden
        role="dialog"
        aria-label="Dịch vụ"
    >
        <div class="service-widget-header__panel" data-service-widget-panel>
            <div class="service-widget-header__loading" data-service-widget-loading hidden>
                Đang tải dịch vụ…
            </div>
            <div class="service-widget-header__error" data-service-widget-error hidden>
                <p data-service-widget-status role="status"></p>
                <button type="button" class="service-widget-header__retry" data-service-widget-retry>
                    Thử lại
                </button>
            </div>
            {{-- wire:ignore: protect Shadow DOM React body across Livewire morphs --}}
            <div
                wire:ignore
                hidden
                data-ops-service-widget-host
                id="ops-service-widget"
                style="display:block;width:100%;min-width:0;height:100%;box-sizing:border-box"
            ></div>
        </div>
    </div>
</div>
