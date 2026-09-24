@php
    use App\Services\SupportTickets\SupportTicketAttachmentService;
    use Filament\Facades\Filament;

    $ticketConfig = app(SupportTicketAttachmentService::class)->clientConfig();

    $panelId = null;
    try {
        $panelId = Filament::getCurrentPanel()?->getId();
    } catch (\Throwable) {
        $panelId = null;
    }

    $service = match ($panelId) {
        'admin' => 'admin',
        'seeding' => 'seeding',
        'seo', 'seo-main' => 'seo',
        default => app(\App\Core\Workspace\ServiceTopbarRouter::class)->activeKey(),
    };

    $connectionHash = null;
    if (in_array($service, ['seo'], true)
        && class_exists(\Omnichannel\Addons\Seo\Support\SeoConnectionContext::class)
    ) {
        $hash = \Omnichannel\Addons\Seo\Support\SeoConnectionContext::resolveHashFromRequest()
            ?? \Omnichannel\Addons\Seo\Support\SeoConnectionContext::hash();
        if (is_string($hash) && \Omnichannel\Addons\Seo\Support\SeoConnectionContext::isValidHashFormat($hash)) {
            $connectionHash = $hash;
        }
    }

    $i18n = [
        'title' => __('support_ticket.title'),
        'titlePlaceholder' => __('support_ticket.title_placeholder'),
        'content' => __('support_ticket.content'),
        'contentPlaceholder' => __('support_ticket.content_placeholder'),
        'attach' => __('support_ticket.attach'),
        'submit' => __('support_ticket.submit'),
        'submitting' => __('support_ticket.submitting'),
        'remove' => __('support_ticket.remove_attachment'),
        'submitted' => __('support_ticket.messages.submitted'),
        'fileTooLarge' => __('support_ticket.messages.file_too_large'),
        'submitFailed' => __('support_ticket.messages.submit_failed'),
    ];

    $ticketProps = [
        'storeUrl' => route('support-tickets.store'),
        'csrfToken' => csrf_token(),
        'pageUrl' => url()->current(),
        'connectionHash' => $connectionHash,
        'routeName' => optional(request()->route())->getName(),
        'service' => $service,
        'accept' => implode(',', array_map(
            static fn (string $ext): string => '.'.$ext,
            $ticketConfig['allowed_extensions'] ?? [],
        )),
        'maxFileSizeBytes' => $ticketConfig['max_file_size_bytes'] ?? (5 * 1024 * 1024),
        'i18n' => $i18n,
    ];
@endphp

@vite([
    'resources/js/support-ticket/headerTicketComposer.js',
    'resources/css/support-ticket-header.css',
])

<div
    class="support-ticket-header"
    data-support-ticket-header
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
>
    <button
        type="button"
        class="support-ticket-header__trigger"
        data-support-ticket-trigger
        title="{{ __('support_ticket.trigger') }}"
        aria-label="{{ __('support_ticket.trigger_aria') }}"
        aria-haspopup="dialog"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        x-on:click="open = ! open"
    >
        <svg class="support-ticket-header__icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
        </svg>
        <span class="support-ticket-header__label-text">{{ __('support_ticket.trigger') }}</span>
    </button>

    <div
        class="support-ticket-header__scrim"
        x-show="open"
        x-cloak
        x-on:click="open = false"
        aria-hidden="true"
    ></div>

    <div
        class="support-ticket-header__popover"
        x-show="open"
        x-cloak
        x-transition.opacity.duration.120ms
        role="dialog"
        aria-label="{{ __('support_ticket.dialog_aria') }}"
        @click.stop
    >
        <div
            id="global-header-ticket-root"
            data-props='@json($ticketProps)'
        ></div>
    </div>
</div>
