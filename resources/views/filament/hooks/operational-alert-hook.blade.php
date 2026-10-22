{{-- Operational Alert Hook — shared shell mount; SEO addon owns Livewire + query. --}}
@php
    $canRenderAlertHook = false;
    if (class_exists(\Livewire\Livewire::class)) {
        try {
            app(\Livewire\Mechanisms\ComponentRegistry::class)->getClass('operational-alert-hook');
            $canRenderAlertHook = true;
        } catch (\Throwable) {
            $canRenderAlertHook = false;
        }
    }
@endphp
@if ($canRenderAlertHook)
    @livewire('operational-alert-hook')
@endif
