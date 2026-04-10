<?php

use App\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component {
    public string $kitchenStatus = '';

    public function mount(): void
    {
        $this->kitchenStatus = cache('kitchen_status', '');
    }

    public function getListeners()
    {
        return [
            'echo:orders,OrderReady' => '$refresh',
            'echo:orders,OrderCompleted' => '$refresh',
            'echo:orders,KitchenStatusUpdated' => 'updateStatus',
        ];
    }

    public function updateStatus(array $event): void
    {
        $this->kitchenStatus = $event['message'] ?? '';
    }

    #[Computed]
    public function recentOrders()
    {
        return Order::ready()->latest()->take(10)->get();
    }

    #[Computed]
    public function otherOrders()
    {
        return Order::ready()->latest()->skip(10)->take(20)->get();
    }
};
?>
<flux:main>
    <div x-data="{ playSound() { console.log('🔔 Bell sound triggered'); new Audio('/sound/bell.mp3').play() } }" @echo:orders,OrderReady.window="playSound()">

        <div class="text-center mb-8">
            <flux:text class="text-5xl lg:text-7xl font-black tracking-tight uppercase text-center">
                {{ __('Orders') }}</flux:text>
            <flux:text class="text-2xl mt-4 uppercase">{{ __('Please collect your food') }}</flux:text>
        </div>

        @if ($kitchenStatus)
            <flux:callout variant="warning" icon="megaphone" class:icon="size-8" class="mb-6">
                <flux:callout.heading class="text-2xl!">
                    {{ $kitchenStatus }}
                </flux:callout.heading>
            </flux:callout>
        @endif

        <div class="flex-1 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-8" x-transition>
            <!-- RECENT CALLS (MAIN FOCUS) -->
            @foreach ($this->recentOrders() as $index => $order)
                <flux:card class="text-center flex flex-col items-center justify-center">
                    <flux:text class="text-4xl md:text-7xl xl:text-9xl font-black tracking-tighter">
                        {{ $order->number }}
                    </flux:text>
                </flux:card>
            @endforeach
        </div>

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

        <!-- Empty state -->
        @if ($this->recentOrders()->count() === 0)
            <div class="flex-1 flex flex-col items-center justify-center text-center">
                <flux:icon.chef-hat class="w-32 h-32 text-zinc-400 mb-8" />
                <flux:text class="text-3xl font-bold uppercase tracking-widest text-center">
                    {{ __('Preparing Orders...') }}
                </flux:text>
            </div>
        @endif

        <!-- Desktop Fixed QR Code Upsell -->
        <div class="fixed bottom-16 right-6 z-50 hidden md:block transition-opacity duration-500 ease-in-out">
            <flux:card class="flex flex-row items-center shadow-xl gap-4">
                <div class="flex flex-col gap-2 flex-1">
                    <flux:text class="text-4xl font-bold">
                        {{ __('Rather wait somewhere else?') }}
                    </flux:text>
                    <flux:text class="text-2xl">
                        {{ __('Scan the QR code to track your order on your phone, and we will notify you when it\'s ready.') }}
                    </flux:text>
                </div>
                <div class="shrink-0 overflow-hidden flex items-center justify-center">
                    <x-qr-code class="w-50 h-50 fill-current text-blue-800 dark:text-zinc-300" />
                </div>
            </flux:card>
        </div>
    </div>
</flux:main>
