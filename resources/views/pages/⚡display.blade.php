<?php

use App\Events\OrdersUpdated;
use App\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component {
    /**
     * Most orders the "being prepared" board shows; the rest are summarised as "+N more".
     */
    private const PREPARING_BOARD_LIMIT = 24;

    /**
     * How long a newly received order is highlighted on the board.
     */
    private const NEW_ORDER_HIGHLIGHT_SECONDS = 45;

    public string $kitchenStatus = '';

    public int $concurrentSponsors = 1;

    public int $recentOrdersCount = 0;

    public function mount(): void
    {
        if (auth()->check() && auth()->user()->is_admin) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->kitchenStatus = cache('kitchen_status', '');
        $this->concurrentSponsors = max(1, min(6, (int) cache('display.concurrent_sponsors', 1)));
        $this->recentOrdersCount = Order::ready()->latest()->take(9)->count();
    }

    public function getListeners()
    {
        return [
            'echo:orders,OrderReady' => 'orderBecameReady',
            'echo:orders,OrderCompleted' => 'refreshOrders',
            'echo:orders,OrderReceived' => 'refreshOrders',
            'echo:orders,OrdersUpdated' => 'ordersUpdated',
            'echo:orders,KitchenStatusUpdated' => 'updateStatus',
        ];
    }

    public function updateStatus(array $event): void
    {
        $this->kitchenStatus = $event['message'] ?? '';
    }

    public function refreshOrders(): void
    {
        $this->recentOrdersCount = Order::ready()->latest()->take(9)->count();
    }

    /**
     * Echo events from Livewire don't bubble to the window, so the bell is rung with a browser event of our own.
     */
    public function orderBecameReady(): void
    {
        $this->refreshOrders();
        $this->dispatch('ring-bell');
    }

    /**
     * Several orders changed at once in the kitchen: refresh once and ring the bell once.
     *
     * @param  array{change?: string}  $event
     */
    public function ordersUpdated(array $event): void
    {
        $this->refreshOrders();

        if (($event['change'] ?? null) === OrdersUpdated::BECAME_READY) {
            $this->dispatch('ring-bell');
        }
    }

    #[Computed]
    public function recentOrders()
    {
        return Order::ready()->latest()->take(9)->get();
    }

    /**
     * Oldest first: the order at the top is the next one the kitchen will call.
     */
    #[Computed]
    public function ordersInPreparation()
    {
        return Order::inPreparation()->oldest('updated_at')->oldest('id')->take(self::PREPARING_BOARD_LIMIT)->get();
    }

    #[Computed]
    public function preparingCount(): int
    {
        return Order::inPreparation()->count();
    }

    /**
     * With no orders at all the sponsors get the space of the ready grid.
     */
    #[Computed]
    public function hasNoOrders(): bool
    {
        return $this->recentOrders->isEmpty() && $this->preparingCount === 0;
    }

    /**
     * Seconds left to highlight this order as just received.
     */
    public function highlightSecondsLeft(Order $order): int
    {
        return max(0, self::NEW_ORDER_HIGHLIGHT_SECONDS - (int) $order->updated_at->diffInSeconds(now()));
    }

    #[Computed]
    public function otherOrders()
    {
        return Order::ready()->latest()->skip(9)->take(20)->get();
    }
};
?>
<flux:main>
    @push('meta')
        <meta name="description" content="{{ __('Live overview of all orders that are ready for pick-up.') }}">
        <meta name="robots" content="noindex, nofollow">
    @endpush
    @push('og')
        <meta property="og:description" content="{{ __('Live overview of all orders that are ready for pick-up.') }}">
    @endpush
    @push('twitter')
        <meta name="twitter:description" content="{{ __('Live overview of all orders that are ready for pick-up.') }}">
    @endpush

    @include('partials.display-ad-grid-block')

    <div x-data="displayAdGridBlock({
        adsEndpoint: @js(url('/api/ads?screen=kitchen')),
        concurrentSponsors: @js($this->concurrentSponsors),
    })" x-init="init()" @resize.window.debounce.150ms="handleResize()"
        @beforeunload.window="destroyTimers()" x-on:ring-bell.window="playSound()">

        <div class="text-center mb-8">
            <flux:text class="text-5xl lg:text-7xl font-black tracking-tight uppercase text-center">
                {{ __('Orders') }}</flux:text>
        </div>

        @if ($kitchenStatus)
            <flux:callout variant="warning" icon="megaphone" class:icon="size-8" class="mb-6">
                <flux:callout.heading class="text-2xl!">
                    {{ $kitchenStatus }}
                </flux:callout.heading>
            </flux:callout>
        @endif

        {{-- Fixed layout: "being prepared" beside "ready for pick-up" on the TV (below it on phones).
             Only the numbers and sponsors inside the two areas change. --}}
        <div class="flex flex-col gap-8 lg:flex-row">
            <aside class="order-last lg:order-first lg:w-80 xl:w-96 2xl:w-md lg:shrink-0 lg:self-start lg:sticky lg:top-8"
                data-test="preparing-board">
                <div class="flex items-baseline justify-between gap-4 mb-1">
                    <flux:heading size="xl" class="text-2xl! xl:text-3xl! uppercase">{{ __('Being prepared') }}</flux:heading>
                    <flux:badge size="lg" class="text-2xl! font-bold tabular-nums px-3!" data-test="preparing-count">{{ $this->preparingCount }}</flux:badge>
                </div>
                <flux:text class="mb-4 text-lg">{{ __('We call your number as soon as it is ready.') }}</flux:text>

                @if ($this->ordersInPreparation->isNotEmpty())
                    <div class="grid grid-cols-4 gap-2 sm:gap-3 lg:grid-cols-3">
                        @foreach ($this->ordersInPreparation as $order)
                            @php($highlightFor = $this->highlightSecondsLeft($order))
                            <flux:card wire:key="preparing-{{ $order->id }}" variant="outline" size="sm"
                                x-data="{ isNew: {{ $highlightFor > 0 ? 'true' : 'false' }} }"
                                x-init="if (isNew) setTimeout(() => isNew = false, {{ $highlightFor * 1000 }})"
                                class="px-0! py-2! sm:py-2.5! text-center transition-colors duration-1000"
                                x-bind:class="isNew && 'bg-amber-400! border-amber-400!'">
                                <flux:text class="text-2xl sm:text-4xl lg:text-3xl xl:text-4xl 2xl:text-5xl font-bold tabular-nums tracking-tight"
                                    x-bind:class="isNew && 'text-amber-950!'">
                                    {{ $order->number }}
                                </flux:text>
                            </flux:card>
                        @endforeach

                        @if ($this->preparingCount > $this->ordersInPreparation->count())
                            <flux:text class="col-span-full py-2 text-center text-xl! font-semibold">
                                {{ __('+ :count more', ['count' => $this->preparingCount - $this->ordersInPreparation->count()]) }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </aside>

        <div class="min-w-0 flex-1">
        <flux:heading size="xl" class="text-2xl! xl:text-3xl! uppercase mb-5">{{ __('Ready for pick-up') }}</flux:heading>

        <!-- Mobile upsell to the track page for specific tracking -->
        <div class="mb-4 md:hidden" x-data="{ visible: true }" x-show="visible" x-collapse>
            <div x-show="visible" x-transition>
                <flux:callout icon="bell" variant="secondary">
                    <flux:callout.heading>{{ __('Get notified when it\'s ready') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Scan the QR code, enter your order number, and enable notifications on your phone.') }}
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button href="{{ route('track') }}" data-umami-event="display-track-button">{{ __('Track your order') }}</flux:button>
                    </x-slot>
                    <x-slot name="controls">
                        <flux:button icon="x-mark" variant="ghost" x-on:click="visible = false" />
                    </x-slot>
                </flux:callout>
            </div>
        </div>
        @unless ($this->hasNoOrders)
            {{-- Keep the column steps in sync with resolveColumns() in partials/display-ad-grid-block --}}
            <div class="mb-8 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">

                @foreach ($this->recentOrders as $order)
                    <div wire:key="order-{{ $order->id }}" style="order: {{ $loop->iteration }}">
                        <flux:card class="text-center flex h-55 flex-col items-center justify-center">
                            <flux:text class="text-4xl md:text-6xl xl:text-7xl 2xl:text-8xl font-black tracking-tighter">
                                {{ $order->number }}
                            </flux:text>
                        </flux:card>
                    </div>
                @endforeach

                <template x-for="(ad, adIndex) in visibleAds" :key="`ad-${activeAdIndex}-${adIndex}`">
                    <div x-bind:style="'order: ' + (Math.min($wire.recentOrdersCount, columns) + adIndex + 1)">
                        <flux:card class="flex h-55 flex-col gap-3 p-4"
                            x-bind:class="ad.call_to_action ? 'cursor-pointer' : ''"
                            x-on:click="if (ad.call_to_action) { window.open(ad.call_to_action, '_blank', 'noopener,noreferrer'); }"
                            x-on:keydown.enter.prevent="if (ad.call_to_action) { window.open(ad.call_to_action, '_blank', 'noopener,noreferrer'); }"
                            x-bind:tabindex="ad.call_to_action ? 0 : -1">
                            <div class="h-full w-full overflow-hidden">
                                <img :src="ad.image_url" :alt="ad.sponsor_name" class="h-full w-full object-contain"
                                    loading="lazy" />
                            </div>
                            <div class="space-y-1 text-center">
                                <flux:text x-show="ad.title" x-text="ad.title"></flux:text>
                            </div>
                        </flux:card>
                    </div>
                </template>
            </div>
        @else
            <!-- No orders at all: the sponsors get the space of the ready grid -->
            <div x-show="visibleAds.length > 0" x-transition>
                {{-- Up to three per row at a moderate size, centred in the ready area --}}
                <div class="grid justify-center gap-5 grid-cols-1"
                    x-bind:style="columns >= 4 && 'grid-template-columns: repeat(' + Math.min(visibleAds.length, 3) + ', minmax(0, 24rem))'">
                    <template x-for="(sponsorAd, adIndex) in visibleAds" :key="`sponsor-${activeAdIndex}-${adIndex}`">

                        <flux:card class="flex flex-col gap-4 p-5"
                            x-bind:style="'height: ' + (visibleAds.length > 3 ? '13rem' : '18rem')"
                            x-bind:class="sponsorAd.call_to_action ? 'cursor-pointer' : ''"
                            x-on:click="if (sponsorAd.call_to_action) { window.open(sponsorAd.call_to_action, '_blank', 'noopener,noreferrer'); }"
                            x-on:keydown.enter.prevent="if (sponsorAd.call_to_action) { window.open(sponsorAd.call_to_action, '_blank', 'noopener,noreferrer'); }"
                            x-bind:tabindex="sponsorAd.call_to_action ? 0 : -1">

                            <div class="flex-1 w-full" style="min-height: 0;">
                                <img :src="sponsorAd.image_url" class="w-full h-full object-contain" />
                            </div>

                            <div class="space-y-1 text-center shrink-0">
                                <flux:text x-show="sponsorAd.title" x-text="sponsorAd.title"></flux:text>
                            </div>

                        </flux:card>

                    </template>
                </div>
            </div>
        @endunless

        <!-- OTHER READY ORDERS -->
        @if ($this->otherOrders()->count() > 0)
            <div class="mt-auto lg:mt-4 max-w-lg">
                <flux:heading size="xl">{{ __('Also Ready') }}</flux:heading>
                <div class="flex flex-wrap justify-start gap-4 mt-4">
                    @foreach ($this->otherOrders() as $order)
                        <flux:card>
                            <flux:text class="text-2xl md:text-6xl font-bold">
                                {{ $order->number }}
                            </flux:text>
                        </flux:card>
                    @endforeach
                </div>
            </div>
        @endif

        </div>
        </div>

        <!-- Desktop Fixed QR Code Upsell -->
        <div class="fixed bottom-16 right-6 z-50 hidden lg:block transition-opacity duration-500 ease-in-out">
            <flux:card class="flex flex-row items-center shadow-xl gap-4">
                <div class="flex flex-col gap-2 flex-1">
                    <flux:text class="text-4xl font-bold">
                        {{ __('Rather wait somewhere else?') }}
                    </flux:text>
                    <flux:text class="text-2xl">
                        {{ __('1. Scan the QR code. 2. Enter your order number. 3. Allow notifications on your phone.') }}
                    </flux:text>
                    <flux:text class="text-2xl">
                        {{ __('Or visit') }} <flux:link variant="ghost" href="{{ route('track') }}">
                            {{ route('track') }}</flux:link>
                    </flux:text>
                </div>
                <div class="shrink-0 overflow-hidden flex items-center justify-center">
                    <x-qr-code class="w-50 h-50 fill-current text-blue-800 dark:text-zinc-300" />
                </div>
            </flux:card>
        </div>
    </div>
</flux:main>
