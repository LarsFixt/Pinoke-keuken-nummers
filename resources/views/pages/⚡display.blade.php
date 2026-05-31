<?php

use App\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $kitchenStatus = '';

    public int $concurrentSponsors = 1;

    public int $recentOrdersCount = 0;

    public function mount(): void
    {
        $this->kitchenStatus = cache('kitchen_status', '');
        $this->concurrentSponsors = max(1, min(6, (int) cache('display.concurrent_sponsors', 1)));
        $this->recentOrdersCount = Order::ready()->latest()->take(9)->count();
    }

    public function getListeners()
    {
        return [
            'echo:orders,OrderReady' => 'refreshOrders',
            'echo:orders,OrderCompleted' => 'refreshOrders',
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

    #[Computed]
    public function recentOrders()
    {
        return Order::ready()->latest()->take(9)->get();
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
        @beforeunload.window="destroyTimers()" x-on:echo:orders,OrderReady.window="playSound()">

        <div class="text-center mb-8">
            <flux:text class="text-5xl lg:text-7xl font-black tracking-tight uppercase text-center">
                {{ __('Orders') }}</flux:text>
            <flux:text class="text-2xl mt-4 uppercase">{{ __('Ready for pick-up') }}</flux:text>
        </div>

        @if ($kitchenStatus)
            <flux:callout variant="warning" icon="megaphone" class:icon="size-8" class="mb-6">
                <flux:callout.heading class="text-2xl!">
                    {{ $kitchenStatus }}
                </flux:callout.heading>
            </flux:callout>
        @endif

        <!-- Mobile upsell to the track page for specific tracking -->
        <div class="mb-4 md:hidden" x-data="{ visible: true }" x-show="visible" x-collapse>
            <div x-show="visible" x-transition>
                <flux:callout icon="bell" variant="secondary">
                    <flux:callout.heading>{{ __('Get notified when it\'s ready') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Scan the QR code, enter your order number, and enable notifications on your phone.') }}
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button href="{{ route('track') }}">{{ __('Track your order') }}</flux:button>
                    </x-slot>
                    <x-slot name="controls">
                        <flux:button icon="x-mark" variant="ghost" x-on:click="visible = false" />
                    </x-slot>
                </flux:callout>
            </div>
        </div>
        @if ($this->recentOrders->isNotEmpty())
            <div class="mb-8 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                @foreach ($this->recentOrders as $order)
                    <div wire:key="order-{{ $order->id }}" style="order: {{ $loop->iteration }}">
                        <flux:card class="text-center flex h-75 flex-col items-center justify-center">
                            <flux:text class="text-4xl md:text-7xl xl:text-9xl font-black tracking-tighter">
                                {{ $order->number }}
                            </flux:text>
                        </flux:card>
                    </div>
                @endforeach

                <template x-for="(ad, adIndex) in visibleAds" :key="`ad-${activeAdIndex}-${adIndex}`">
                    <div x-bind:style="'order: ' + (Math.min($wire.recentOrdersCount, columns) + adIndex + 1)">
                        <flux:card class="flex h-75 flex-col gap-3 p-4"
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
            <!-- Empty state -->
            <div class="mb-15 flex flex-col items-center justify-center">
                <div class="flex-1 flex flex-col items-center justify-center text-center">
                    <flux:icon.chef-hat class="w-32 h-32 text-zinc-400 mb-8" />
                    <flux:text class="text-3xl font-bold uppercase tracking-widest text-center">
                        {{ __('Preparing Orders...') }}
                    </flux:text>
                </div>
            </div>

            <!-- Bigger sponsor overview when no orders are ready -->
            <div class="mt-10" x-show="visibleAds.length > 0" x-transition>
                <div class="flex flex-wrap justify-center gap-5">
                    <template x-for="(sponsorAd, adIndex) in visibleAds" :key="`sponsor-${activeAdIndex}-${adIndex}`">
                        <flux:card class="flex h-72 flex-col gap-4 p-5 w-full lg:w-1/2"
                            x-bind:class="sponsorAd.call_to_action ? 'cursor-pointer' : ''"
                            x-on:click="if (sponsorAd.call_to_action) { window.open(sponsorAd.call_to_action, '_blank', 'noopener,noreferrer'); }"
                            x-on:keydown.enter.prevent="if (sponsorAd.call_to_action) { window.open(sponsorAd.call_to_action, '_blank', 'noopener,noreferrer'); }"
                            x-bind:tabindex="sponsorAd.call_to_action ? 0 : -1">
                            <div class="aspect-4/3 w-full overflow-hidden rounded-lg">
                                <img :src="sponsorAd.image_url" :alt="sponsorAd.sponsor_name"
                                    class="h-full w-full object-contain" loading="lazy" />
                            </div>
                            <div class="space-y-1 text-center">
                                <flux:text x-show="sponsorAd.title" x-text="sponsorAd.title"></flux:text>
                            </div>
                        </flux:card>
                    </template>
                </div>
            </div>
        @endif

        <!-- OTHER READY ORDERS -->
        @if ($this->otherOrders()->count() > 0)
            <div class="mt-auto">

                <flux:separator text="{{ __('Also Ready') }}" text-size="text-2xl" />

                <div class="flex flex-wrap justify-center gap-4 mt-4">
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
