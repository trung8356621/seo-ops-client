@props([
    'paginator',
    'tokens',
    'scrollIntoViewJsSnippet' => '',
    'keyPrefix' => 'p',
])

@foreach ($tokens as $token)
    @if (is_string($token))
        <span aria-disabled="true">
            <span class="relative inline-flex items-center px-4 py-2 -ml-px text-sm font-medium text-gray-700 bg-white border border-gray-300 cursor-default leading-5 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-300">{{ $token }}</span>
        </span>
    @else
        <span wire:key="paginator-{{ $paginator->getPageName() }}-{{ $keyPrefix }}-page{{ $token }}">
            @if ($token === $paginator->currentPage())
                <span aria-current="page">
                    <span class="relative inline-flex items-center px-4 py-2 -ml-px text-sm font-medium text-primary-600 bg-white border border-gray-300 cursor-default leading-5 dark:bg-gray-800 dark:border-gray-600 dark:text-primary-400">{{ $token }}</span>
                </span>
            @else
                <button type="button" wire:click="gotoPage({{ $token }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="relative inline-flex items-center px-4 py-2 -ml-px text-sm font-medium text-gray-700 bg-white border border-gray-300 leading-5 hover:text-gray-500 focus:z-10 focus:outline-none focus:border-blue-300 focus:ring ring-blue-300 active:bg-gray-100 active:text-gray-700 transition ease-in-out duration-150 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 dark:hover:text-gray-300 dark:active:bg-gray-700 dark:focus:border-blue-800" aria-label="{{ __('Go to page :page', ['page' => $token]) }}">
                    {{ $token }}
                </button>
            @endif
        </span>
    @endif
@endforeach
