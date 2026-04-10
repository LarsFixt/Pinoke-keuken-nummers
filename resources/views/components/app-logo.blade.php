@props([
    'sidebar' => false,
])

@if ($sidebar)
    <flux:sidebar.brand name="Pinoké keuken orders" {{ $attributes }}>
        <img src="/img/logo_pinoke_blauw.webp" alt="Pinoké logo" class="size-8 object-contain" />
    </flux:sidebar.brand>
@else
    <flux:brand name="Pinoké keuken orders" {{ $attributes }}>
        <img src="/img/logo_pinoke_blauw.webp" alt="Pinoké logo" class="size-12 object-contain" />
    </flux:brand>
@endif
