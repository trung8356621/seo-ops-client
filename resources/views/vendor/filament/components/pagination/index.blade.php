@props([
    'currentPageOptionProperty' => 'tableRecordsPerPage',
    'extremeLinks' => false,
    'paginator',
    'pageOptions' => [],
])

@php
    use App\Core\Support\PaginationWindow;
    use Illuminate\Contracts\Pagination\CursorPaginator;

    $isRtl = __('filament-panels::layout.direction') === 'rtl';
    $isSimple = ! $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator;
    $pageName = $isSimple ? '' : $paginator->getPageName();
    $pageTokens = $isSimple ? [] : PaginationWindow::tokens(
        $paginator->currentPage(),
        $paginator->lastPage(),
        PaginationWindow::DESKTOP_SIDE,
    );
    $componentId = $this->getId();
@endphp

<nav
    aria-label="{{ __('filament::components/pagination.label') }}"
    role="navigation"
    {{
        $attributes->class([
            'fi-pagination grid grid-cols-[1fr_auto_1fr] items-center gap-x-3',
            'fi-simple' => $isSimple,
        ])
    }}
>
    @if ($isSimple)
        @if (! $paginator->onFirstPage())
            @php
                if ($paginator instanceof CursorPaginator) {
                    $wireClickAction = "setPage('{$paginator->previousCursor()->encode()}', '{$paginator->getCursorName()}')";
                } else {
                    $wireClickAction = "previousPage('{$paginator->getPageName()}')";
                }
            @endphp

            <x-filament::button
                color="gray"
                rel="prev"
                :wire:click="$wireClickAction"
                :wire:key="$componentId . '.pagination.previous'"
                class="fi-pagination-previous-btn justify-self-start"
            >
                {{ __('filament::components/pagination.actions.previous.label') }}
            </x-filament::button>
        @endif
    @elseif ($paginator->hasPages())
        @if ($paginator->onFirstPage())
            <x-filament::button
                color="gray"
                disabled
                rel="prev"
                :wire:key="$componentId . '.pagination.previous.disabled'"
                class="fi-pagination-previous-btn justify-self-start"
            >
                {{ __('filament::components/pagination.actions.previous.label') }}
            </x-filament::button>
        @else
            <x-filament::button
                color="gray"
                rel="prev"
                :wire:click="'previousPage(\'' . $pageName . '\')'"
                :wire:key="$componentId . '.pagination.previous'"
                class="fi-pagination-previous-btn justify-self-start"
            >
                {{ __('filament::components/pagination.actions.previous.label') }}
            </x-filament::button>
        @endif
    @endif

    @if (! $isSimple)
        <span
            class="fi-pagination-overview text-sm font-medium text-gray-700 dark:text-gray-200"
        >
            {{
                trans_choice(
                    'filament::components/pagination.overview',
                    $paginator->total(),
                    [
                        'first' => \Illuminate\Support\Number::format($paginator->firstItem() ?? 0),
                        'last' => \Illuminate\Support\Number::format($paginator->lastItem() ?? 0),
                        'total' => \Illuminate\Support\Number::format($paginator->total()),
                    ],
                )
            }}
        </span>
    @endif

    @if (count($pageOptions) > 1)
        <div class="col-start-2 justify-self-center">
            <label class="fi-pagination-records-per-page-select fi-compact">
                <x-filament::input.wrapper>
                    <x-filament::input.select
                        :wire:model.live="$currentPageOptionProperty"
                    >
                        @foreach ($pageOptions as $option)
                            <option value="{{ $option }}">
                                {{ $option === 'all' ? __('filament::components/pagination.fields.records_per_page.options.all') : $option }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <span class="sr-only">
                    {{ __('filament::components/pagination.fields.records_per_page.label') }}
                </span>
            </label>

            <label class="fi-pagination-records-per-page-select">
                <x-filament::input.wrapper
                    :prefix="__('filament::components/pagination.fields.records_per_page.label')"
                >
                    <x-filament::input.select
                        :wire:model.live="$currentPageOptionProperty"
                    >
                        @foreach ($pageOptions as $option)
                            <option value="{{ $option }}">
                                {{ $option === 'all' ? __('filament::components/pagination.fields.records_per_page.options.all') : $option }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>
    @endif

    @if ($isSimple)
        @if ($paginator->hasMorePages())
            @php
                if ($paginator instanceof CursorPaginator) {
                    $wireClickAction = "setPage('{$paginator->nextCursor()->encode()}', '{$paginator->getCursorName()}')";
                } else {
                    $wireClickAction = "nextPage('{$paginator->getPageName()}')";
                }
            @endphp

            <x-filament::button
                color="gray"
                rel="next"
                :wire:click="$wireClickAction"
                :wire:key="$componentId . '.pagination.next'"
                class="fi-pagination-next-btn col-start-3 justify-self-end"
            >
                {{ __('filament::components/pagination.actions.next.label') }}
            </x-filament::button>
        @endif
    @elseif ($paginator->hasPages())
        @if ($paginator->hasMorePages())
            <x-filament::button
                color="gray"
                rel="next"
                :wire:click="'nextPage(\'' . $pageName . '\')'"
                :wire:key="$componentId . '.pagination.next'"
                class="fi-pagination-next-btn col-start-3 justify-self-end"
            >
                {{ __('filament::components/pagination.actions.next.label') }}
            </x-filament::button>
        @else
            <x-filament::button
                color="gray"
                disabled
                rel="next"
                :wire:key="$componentId . '.pagination.next.disabled'"
                class="fi-pagination-next-btn col-start-3 justify-self-end"
            >
                {{ __('filament::components/pagination.actions.next.label') }}
            </x-filament::button>
        @endif
    @endif

    @if ((! $isSimple) && $paginator->hasPages())
        <ol
            class="fi-pagination-items justify-self-end max-w-full overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
        >
            @if ($extremeLinks)
                @if ($paginator->onFirstPage())
                    <x-filament::pagination.item
                        disabled
                        :aria-label="__('filament::components/pagination.actions.first.label')"
                        :icon="$isRtl ? 'heroicon-m-chevron-double-right' : 'heroicon-m-chevron-double-left'"
                        :icon-alias="$isRtl ? 'pagination.first-button.rtl' : 'pagination.first-button'"
                    />
                @else
                    <x-filament::pagination.item
                        :aria-label="__('filament::components/pagination.actions.first.label')"
                        :icon="$isRtl ? 'heroicon-m-chevron-double-right' : 'heroicon-m-chevron-double-left'"
                        :icon-alias="$isRtl ? 'pagination.first-button.rtl' : 'pagination.first-button'"
                        rel="first"
                        :wire:click="'gotoPage(1, \'' . $pageName . '\')'"
                        :wire:key="$componentId . '.pagination.first'"
                    />
                @endif
            @endif

            @if ($paginator->onFirstPage())
                <x-filament::pagination.item
                    disabled
                    :aria-label="__('filament::components/pagination.actions.previous.label')"
                    :icon="$isRtl ? 'heroicon-m-chevron-right' : 'heroicon-m-chevron-left'"
                    :icon-alias="$isRtl ? ['pagination.previous-button.rtl', 'pagination.previous-button'] : 'pagination.previous-button'"
                />
            @else
                <x-filament::pagination.item
                    :aria-label="__('filament::components/pagination.actions.previous.label')"
                    :icon="$isRtl ? 'heroicon-m-chevron-right' : 'heroicon-m-chevron-left'"
                    :icon-alias="$isRtl ? ['pagination.previous-button.rtl', 'pagination.previous-button'] : 'pagination.previous-button'"
                    rel="prev"
                    :wire:click="'previousPage(\'' . $pageName . '\')'"
                    :wire:key="$componentId . '.pagination.previous.item'"
                />
            @endif

            @foreach ($pageTokens as $token)
                @if (is_string($token))
                    <x-filament::pagination.item disabled :label="$token" />
                @else
                    <x-filament::pagination.item
                        :active="$token === $paginator->currentPage()"
                        :aria-label="trans_choice('filament::components/pagination.actions.go_to_page.label', $token, ['page' => $token])"
                        :label="$token"
                        :wire:click="'gotoPage(' . $token . ', \'' . $pageName . '\')'"
                        :wire:key="$componentId . '.pagination.' . $pageName . '.' . $token"
                    />
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <x-filament::pagination.item
                    :aria-label="__('filament::components/pagination.actions.next.label')"
                    :icon="$isRtl ? 'heroicon-m-chevron-left' : 'heroicon-m-chevron-right'"
                    :icon-alias="$isRtl ? ['pagination.next-button.rtl', 'pagination.next-button'] : 'pagination.next-button'"
                    rel="next"
                    :wire:click="'nextPage(\'' . $pageName . '\')'"
                    :wire:key="$componentId . '.pagination.next.item'"
                />
            @else
                <x-filament::pagination.item
                    disabled
                    :aria-label="__('filament::components/pagination.actions.next.label')"
                    :icon="$isRtl ? 'heroicon-m-chevron-left' : 'heroicon-m-chevron-right'"
                    :icon-alias="$isRtl ? ['pagination.next-button.rtl', 'pagination.next-button'] : 'pagination.next-button'"
                />
            @endif

            @if ($extremeLinks)
                @if ($paginator->hasMorePages())
                    <x-filament::pagination.item
                        :aria-label="__('filament::components/pagination.actions.last.label')"
                        :icon="$isRtl ? 'heroicon-m-chevron-double-left' : 'heroicon-m-chevron-double-right'"
                        :icon-alias="$isRtl ? 'pagination.last-button.rtl' : 'pagination.last-button'"
                        rel="last"
                        :wire:click="'gotoPage(' . $paginator->lastPage() . ', \'' . $pageName . '\')'"
                        :wire:key="$componentId . '.pagination.last'"
                    />
                @else
                    <x-filament::pagination.item
                        disabled
                        :aria-label="__('filament::components/pagination.actions.last.label')"
                        :icon="$isRtl ? 'heroicon-m-chevron-double-left' : 'heroicon-m-chevron-double-right'"
                        :icon-alias="$isRtl ? 'pagination.last-button.rtl' : 'pagination.last-button'"
                    />
                @endif
            @endif
        </ol>
    @endif
</nav>
