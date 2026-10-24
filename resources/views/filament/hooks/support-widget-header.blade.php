@php
    // Relative path only — absolute APP_URL (http) on an https page causes
    // POST→302→GET and MethodNotAllowed on /api/support-widget/session.
    $sessionUrl = route('support-widget.session', absolute: false);
@endphp

@vite([
    'resources/js/support-widget/headerSupportWidget.js',
    'resources/css/support-widget-header.css',
], 'build-support')

<div
    class="support-widget-header"
    data-support-widget-header
    data-session-url="{{ $sessionUrl }}"
>
    <button
        type="button"
        class="support-widget-header__trigger"
        data-support-widget-trigger
        title="Ticket"
        aria-label="Ticket hỗ trợ"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="support-widget-dropdown"
    >
        <svg class="support-widget-header__icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
        </svg>
        <span class="support-widget-header__label">Ticket</span>
    </button>

    <div
        id="support-widget-dropdown"
        class="support-widget-header__dropdown"
        data-support-widget-dropdown
        hidden
        role="dialog"
        aria-label="Gửi hỗ trợ"
    >
        <div class="support-widget-header__panel" data-support-widget-panel>
            <div data-support-widget-loading hidden class="support-widget-header__loading">
                Đang kết nối hỗ trợ…
            </div>
            <div data-support-widget-error hidden class="support-widget-header__error">
                <p data-support-widget-status role="status"></p>
                <button type="button" class="support-widget-header__retry" data-support-widget-retry>
                    Thử lại
                </button>
            </div>
            <div
                wire:ignore
                hidden
                data-ops-support-widget-host
                id="ops-support-widget"
                style="display:block;width:100%;min-width:0;box-sizing:border-box"
            ></div>
        </div>
    </div>
</div>
