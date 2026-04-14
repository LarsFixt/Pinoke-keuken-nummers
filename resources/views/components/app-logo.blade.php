@props([
    'sidebar' => false,
])

@if ($sidebar)
    <flux:sidebar.brand name="Pinoké Order Tracker" {{ $attributes }}>
        <x-app-logo-icon class="size-6" />
    </flux:sidebar.brand>
@else
    <flux:brand name="Pinoké Order Tracker" {{ $attributes }}>
        <x-app-logo-icon class="size-6" />
    </flux:brand>
@endif
