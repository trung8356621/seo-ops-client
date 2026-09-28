<div
    id="global-help-drawer"
    class="global-help-drawer"
    data-help-drawer
    data-help-drawer-host
    data-help-modal
    data-help-modal-host
    x-data="{
        titleId: 'global-help-drawer-title',
        init() {
            const onKeyDown = (event) => {
                const help = window.Alpine?.store?.('help');
                if (!help?.isOpen) {
                    return;
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    help.close();
                    return;
                }
                if (event.key !== 'Tab') {
                    return;
                }
                const dialog = this.$refs.dialog;
                if (!(dialog instanceof HTMLElement)) {
                    return;
                }
                const focusables = dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex=\'-1\'])');
                const list = [...focusables].filter((node) => node instanceof HTMLElement && !node.hasAttribute('disabled') && node.offsetParent !== null);
                if (list.length === 0) {
                    return;
                }
                const first = list[0];
                const last = list[list.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            };
            document.addEventListener('keydown', onKeyDown);
            this._helpKeyDown = onKeyDown;
            this.$watch(() => window.Alpine?.store?.('help')?.isOpen, (open) => {
                if (open) {
                    this.$nextTick(() => this.$refs.closeBtn?.focus?.());
                }
            });
        },
        destroy() {
            if (this._helpKeyDown) {
                document.removeEventListener('keydown', this._helpKeyDown);
            }
            const help = window.Alpine?.store?.('help');
            if (help?.isOpen) {
                help.unlockBody();
            }
        },
    }"
>
    <button
        type="button"
        class="global-help-drawer__scrim"
        data-help-drawer-scrim
        data-help-modal-backdrop
        aria-label="{{ __('help.close_aria') }}"
        x-show="$store.help && $store.help.isOpen"
        x-cloak
        x-transition:enter="global-help-scrim-transition"
        x-transition:enter-start="global-help-scrim-off"
        x-transition:enter-end="global-help-scrim-on"
        x-transition:leave="global-help-scrim-transition"
        x-transition:leave-start="global-help-scrim-on"
        x-transition:leave-end="global-help-scrim-off"
        x-on:click="$store.help.close()"
    ></button>

    <aside
        class="global-help-drawer__panel"
        data-help-drawer-panel
        data-help-modal-dialog
        role="dialog"
        aria-modal="true"
        x-bind:aria-labelledby="titleId"
        x-ref="dialog"
        x-show="$store.help && $store.help.isOpen"
        x-cloak
        x-transition:enter="global-help-drawer-transition"
        x-transition:enter-start="global-help-drawer-offscreen"
        x-transition:enter-end="global-help-drawer-onscreen"
        x-transition:leave="global-help-drawer-transition"
        x-transition:leave-start="global-help-drawer-onscreen"
        x-transition:leave-end="global-help-drawer-offscreen"
    >
        <header class="global-help-drawer__header" data-help-drawer-header data-help-modal-header>
            <div class="global-help-drawer__heading">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                </svg>
                <h2 x-bind:id="titleId" x-text="($store.help && $store.help.modalTitle) || @js(__('help.system_title'))">{{ __('help.system_title') }}</h2>
            </div>
            <button
                type="button"
                class="global-help-drawer__close"
                data-help-drawer-close
                data-help-modal-close
                aria-label="{{ __('help.close') }}"
                x-ref="closeBtn"
                x-on:click="$store.help.close()"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </header>

        <div class="global-help-drawer__toolbar" data-help-drawer-toolbar data-help-modal-toolbar>
            <button
                type="button"
                class="help-mobile-back"
                data-help-mobile-back
                x-show="$store.help.mobileView !== 'groups'"
                x-on:click="$store.help.mobileBack()"
            >
                ← {{ __('help.back') }}
            </button>

            @include('filament.hooks.partials.help-search')
        </div>

        <div
            class="global-help-drawer__body"
            data-help-drawer-body
            data-help-modal-body
            x-bind:data-mobile-view="$store.help.mobileView"
        >
            @include('filament.hooks.partials.help-group-navigation')
            @include('filament.hooks.partials.help-topic-accordion')
        </div>
    </aside>
</div>
