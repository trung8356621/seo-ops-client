@php
    /** @var \App\Core\Workspace\ServiceTopbarRouter $router */
    $router = app(\App\Core\Workspace\ServiceTopbarRouter::class);
    $links = $router->shouldRender() ? $router->links() : [];
@endphp

@if (count($links) > 0)
    <nav
        class="seo-ops-service-router"
        data-service-router
        aria-label="{{ __('services.nav_label') }}"
    >
        @foreach ($links as $link)
            @if ($link['active'])
                <span
                    class="seo-ops-service-router__item is-active"
                    data-service="{{ $link['key'] }}"
                    data-active="1"
                    aria-current="page"
                >{{ $link['label'] }}</span>
            @else
                <a
                    class="seo-ops-service-router__item"
                    href="{{ $link['url'] }}"
                    data-service="{{ $link['key'] }}"
                    data-active="0"
                >{{ $link['label'] }}</a>
            @endif
        @endforeach
    </nav>

    <style>
        /*
         * TOPBAR_START is first in DOM. Flex order: brand/toggles stay 0,
         * router = 1 (beside logo), ms-auto + TOPBAR_END = 2 (far right).
         * order:1 alone would push the router AFTER default-order ms-auto.
         */
        .fi-topbar > nav {
            display: flex;
            align-items: center;
        }
        .seo-ops-service-router {
            order: 1;
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            gap: 0.15rem;
            min-width: 0;
            max-width: min(100%, 22rem);
            margin-inline-end: auto;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .fi-topbar > nav > .ms-auto {
            order: 2;
            margin-inline-start: 0 !important;
        }
        .fi-topbar > nav > .ms-auto ~ * {
            order: 2;
        }
        .seo-ops-service-router::-webkit-scrollbar { display: none; }
        .seo-ops-service-router__item {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.55rem;
            border-radius: 0.45rem;
            font-size: 0.8125rem;
            font-weight: 650;
            line-height: 1.2;
            color: #6b7280;
            text-decoration: none;
            white-space: nowrap;
            transition: background-color .12s ease, color .12s ease;
        }
        .seo-ops-service-router__item:hover {
            color: #111827;
            background: #f3f4f6;
        }
        .seo-ops-service-router__item.is-active {
            color: #c2410c;
            background: #fff7ed;
            cursor: default;
        }
        @media (max-width: 640px) {
            .seo-ops-service-router {
                max-width: min(100%, 11.5rem);
                gap: 0.05rem;
            }
            .seo-ops-service-router__item {
                padding: 0.25rem 0.4rem;
                font-size: 0.75rem;
            }
        }
    </style>
@endif
