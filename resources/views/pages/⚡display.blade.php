<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use App\Models\Order;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component {
    public function getListeners()
    {
        return [
            'echo:orders,OrderReady' => '$refresh',
            'echo:orders,OrderCompleted' => '$refresh',
        ];
    }

    #[Computed]
    public function recentOrders()
    {
        return Order::ready()->latest()->take(3)->get();
    }

    #[Computed]
    public function otherOrders()
    {
        return Order::ready()->latest()->skip(3)->take(20)->get();
    }
};
?>

<flux:main>
    <div x-data="{ playSound() { new Audio('/sound/bell.mp3').play() } }" @echo:orders,OrderReady.window="playSound()">

        <div class="text-center mb-12">
            <h1 class="text-5xl lg:text-7xl font-black tracking-tight uppercase text-neutral-300" class="text-center">
                {{ __('Order Ready') }}</h1>
            <flux:text class="text-2xl mt-4 uppercase">{{ __('Please collect your food') }}</flux:text>
        </div>

        <div class="flex-1 grid grid-cols-1 md:grid-cols-4 gap-8 mb-12" x-transition>
            <!-- RECENT CALLS (MAIN FOCUS) -->
            @foreach ($this->recentOrders() as $index => $order)
                <flux:card class="text-center flex flex-col items-center justify-center">
                    <flux:heading size="xl" class="uppercase">
                        {{ $index === 0 ? __('Now Serving') : __('Ready') }}
                    </flux:heading>
                    <div class="text-7xl md:text-9xl font-black tracking-tighter text-zinc-300">
                        {{ $order->number }}
                    </div>
                </flux:card>
            @endforeach

            @if ($this->recentOrders()->count() > 0)
                <flux:card class="text-center flex flex-col items-center justify-center">
                    <flux:heading size="xl" class="uppercase">
                        {{ __('Scan to view on your phone') }}
                    </flux:heading>
                    <svg xmlns="http://www.w3.org/2000/svg" id="qrcode-svg" stroke="none" viewBox="0 0 33 33">
                        <rect width="100%" height="100%" fill="#333238"></rect>
                        <path
                            d="M4,4h1v1h-1z M5,4h1v1h-1z M6,4h1v1h-1z M7,4h1v1h-1z M8,4h1v1h-1z M9,4h1v1h-1z M10,4h1v1h-1z M13,4h1v1h-1z M14,4h1v1h-1z M17,4h1v1h-1z M20,4h1v1h-1z M22,4h1v1h-1z M23,4h1v1h-1z M24,4h1v1h-1z M25,4h1v1h-1z M26,4h1v1h-1z M27,4h1v1h-1z M28,4h1v1h-1z M4,5h1v1h-1z M10,5h1v1h-1z M13,5h1v1h-1z M14,5h1v1h-1z M15,5h1v1h-1z M16,5h1v1h-1z M17,5h1v1h-1z M18,5h1v1h-1z M19,5h1v1h-1z M20,5h1v1h-1z M22,5h1v1h-1z M28,5h1v1h-1z M4,6h1v1h-1z M6,6h1v1h-1z M7,6h1v1h-1z M8,6h1v1h-1z M10,6h1v1h-1z M12,6h1v1h-1z M15,6h1v1h-1z M19,6h1v1h-1z M22,6h1v1h-1z M24,6h1v1h-1z M25,6h1v1h-1z M26,6h1v1h-1z M28,6h1v1h-1z M4,7h1v1h-1z M6,7h1v1h-1z M7,7h1v1h-1z M8,7h1v1h-1z M10,7h1v1h-1z M12,7h1v1h-1z M15,7h1v1h-1z M17,7h1v1h-1z M18,7h1v1h-1z M22,7h1v1h-1z M24,7h1v1h-1z M25,7h1v1h-1z M26,7h1v1h-1z M28,7h1v1h-1z M4,8h1v1h-1z M6,8h1v1h-1z M7,8h1v1h-1z M8,8h1v1h-1z M10,8h1v1h-1z M12,8h1v1h-1z M14,8h1v1h-1z M15,8h1v1h-1z M17,8h1v1h-1z M20,8h1v1h-1z M22,8h1v1h-1z M24,8h1v1h-1z M25,8h1v1h-1z M26,8h1v1h-1z M28,8h1v1h-1z M4,9h1v1h-1z M10,9h1v1h-1z M12,9h1v1h-1z M16,9h1v1h-1z M17,9h1v1h-1z M18,9h1v1h-1z M19,9h1v1h-1z M22,9h1v1h-1z M28,9h1v1h-1z M4,10h1v1h-1z M5,10h1v1h-1z M6,10h1v1h-1z M7,10h1v1h-1z M8,10h1v1h-1z M9,10h1v1h-1z M10,10h1v1h-1z M12,10h1v1h-1z M14,10h1v1h-1z M16,10h1v1h-1z M18,10h1v1h-1z M20,10h1v1h-1z M22,10h1v1h-1z M23,10h1v1h-1z M24,10h1v1h-1z M25,10h1v1h-1z M26,10h1v1h-1z M27,10h1v1h-1z M28,10h1v1h-1z M12,11h1v1h-1z M13,11h1v1h-1z M14,11h1v1h-1z M18,11h1v1h-1z M19,11h1v1h-1z M4,12h1v1h-1z M6,12h1v1h-1z M7,12h1v1h-1z M8,12h1v1h-1z M9,12h1v1h-1z M10,12h1v1h-1z M13,12h1v1h-1z M15,12h1v1h-1z M16,12h1v1h-1z M17,12h1v1h-1z M18,12h1v1h-1z M19,12h1v1h-1z M20,12h1v1h-1z M22,12h1v1h-1z M23,12h1v1h-1z M24,12h1v1h-1z M25,12h1v1h-1z M26,12h1v1h-1z M4,13h1v1h-1z M5,13h1v1h-1z M6,13h1v1h-1z M8,13h1v1h-1z M15,13h1v1h-1z M16,13h1v1h-1z M17,13h1v1h-1z M20,13h1v1h-1z M23,13h1v1h-1z M27,13h1v1h-1z M5,14h1v1h-1z M6,14h1v1h-1z M7,14h1v1h-1z M8,14h1v1h-1z M10,14h1v1h-1z M11,14h1v1h-1z M16,14h1v1h-1z M17,14h1v1h-1z M19,14h1v1h-1z M21,14h1v1h-1z M22,14h1v1h-1z M23,14h1v1h-1z M24,14h1v1h-1z M25,14h1v1h-1z M27,14h1v1h-1z M28,14h1v1h-1z M5,15h1v1h-1z M6,15h1v1h-1z M7,15h1v1h-1z M8,15h1v1h-1z M12,15h1v1h-1z M14,15h1v1h-1z M18,15h1v1h-1z M20,15h1v1h-1z M21,15h1v1h-1z M22,15h1v1h-1z M24,15h1v1h-1z M28,15h1v1h-1z M8,16h1v1h-1z M10,16h1v1h-1z M11,16h1v1h-1z M12,16h1v1h-1z M13,16h1v1h-1z M15,16h1v1h-1z M16,16h1v1h-1z M17,16h1v1h-1z M18,16h1v1h-1z M21,16h1v1h-1z M22,16h1v1h-1z M24,16h1v1h-1z M26,16h1v1h-1z M27,16h1v1h-1z M28,16h1v1h-1z M4,17h1v1h-1z M9,17h1v1h-1z M13,17h1v1h-1z M21,17h1v1h-1z M23,17h1v1h-1z M25,17h1v1h-1z M27,17h1v1h-1z M4,18h1v1h-1z M7,18h1v1h-1z M8,18h1v1h-1z M9,18h1v1h-1z M10,18h1v1h-1z M13,18h1v1h-1z M14,18h1v1h-1z M15,18h1v1h-1z M16,18h1v1h-1z M18,18h1v1h-1z M19,18h1v1h-1z M21,18h1v1h-1z M22,18h1v1h-1z M23,18h1v1h-1z M24,18h1v1h-1z M25,18h1v1h-1z M27,18h1v1h-1z M28,18h1v1h-1z M4,19h1v1h-1z M6,19h1v1h-1z M7,19h1v1h-1z M14,19h1v1h-1z M15,19h1v1h-1z M18,19h1v1h-1z M20,19h1v1h-1z M21,19h1v1h-1z M22,19h1v1h-1z M23,19h1v1h-1z M24,19h1v1h-1z M28,19h1v1h-1z M4,20h1v1h-1z M6,20h1v1h-1z M7,20h1v1h-1z M9,20h1v1h-1z M10,20h1v1h-1z M11,20h1v1h-1z M15,20h1v1h-1z M16,20h1v1h-1z M17,20h1v1h-1z M20,20h1v1h-1z M21,20h1v1h-1z M22,20h1v1h-1z M23,20h1v1h-1z M24,20h1v1h-1z M26,20h1v1h-1z M12,21h1v1h-1z M13,21h1v1h-1z M16,21h1v1h-1z M17,21h1v1h-1z M19,21h1v1h-1z M20,21h1v1h-1z M24,21h1v1h-1z M25,21h1v1h-1z M4,22h1v1h-1z M5,22h1v1h-1z M6,22h1v1h-1z M7,22h1v1h-1z M8,22h1v1h-1z M9,22h1v1h-1z M10,22h1v1h-1z M13,22h1v1h-1z M16,22h1v1h-1z M17,22h1v1h-1z M18,22h1v1h-1z M20,22h1v1h-1z M22,22h1v1h-1z M24,22h1v1h-1z M26,22h1v1h-1z M27,22h1v1h-1z M28,22h1v1h-1z M4,23h1v1h-1z M10,23h1v1h-1z M12,23h1v1h-1z M14,23h1v1h-1z M20,23h1v1h-1z M24,23h1v1h-1z M25,23h1v1h-1z M27,23h1v1h-1z M28,23h1v1h-1z M4,24h1v1h-1z M6,24h1v1h-1z M7,24h1v1h-1z M8,24h1v1h-1z M10,24h1v1h-1z M12,24h1v1h-1z M13,24h1v1h-1z M17,24h1v1h-1z M18,24h1v1h-1z M19,24h1v1h-1z M20,24h1v1h-1z M21,24h1v1h-1z M22,24h1v1h-1z M23,24h1v1h-1z M24,24h1v1h-1z M26,24h1v1h-1z M4,25h1v1h-1z M6,25h1v1h-1z M7,25h1v1h-1z M8,25h1v1h-1z M10,25h1v1h-1z M12,25h1v1h-1z M15,25h1v1h-1z M17,25h1v1h-1z M21,25h1v1h-1z M22,25h1v1h-1z M24,25h1v1h-1z M25,25h1v1h-1z M26,25h1v1h-1z M27,25h1v1h-1z M28,25h1v1h-1z M4,26h1v1h-1z M6,26h1v1h-1z M7,26h1v1h-1z M8,26h1v1h-1z M10,26h1v1h-1z M12,26h1v1h-1z M13,26h1v1h-1z M17,26h1v1h-1z M18,26h1v1h-1z M25,26h1v1h-1z M26,26h1v1h-1z M28,26h1v1h-1z M4,27h1v1h-1z M10,27h1v1h-1z M13,27h1v1h-1z M14,27h1v1h-1z M19,27h1v1h-1z M20,27h1v1h-1z M23,27h1v1h-1z M24,27h1v1h-1z M25,27h1v1h-1z M28,27h1v1h-1z M4,28h1v1h-1z M5,28h1v1h-1z M6,28h1v1h-1z M7,28h1v1h-1z M8,28h1v1h-1z M9,28h1v1h-1z M10,28h1v1h-1z M12,28h1v1h-1z M13,28h1v1h-1z M16,28h1v1h-1z M17,28h1v1h-1z M18,28h1v1h-1z M21,28h1v1h-1z M23,28h1v1h-1z M24,28h1v1h-1z M25,28h1v1h-1z M26,28h1v1h-1z M27,28h1v1h-1z M28,28h1v1h-1z"
                            fill="#d4d4d4"></path>
                    </svg>
                </flux:card>
            @endif

        </div>

        <!-- OTHER READY ORDERS -->
        @if ($this->otherOrders()->count() > 0)
            <div class="mt-auto">

                <flux:separator text="{{ __('Also Ready') }}" text-size="text-2xl" />

                <div class="flex flex-wrap justify-center gap-4 mt-4">
                    @foreach ($this->otherOrders() as $order)
                        <flux:card>
                            <div class="text-6xl font-bold text-zinc-300">
                                {{ $order->number }}
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Empty state -->
        @if ($this->recentOrders()->count() === 0)
            <div class="flex-1 flex flex-col items-center justify-center">
                <flux:icon.clock class="w-32 h-32 text-zinc-800 mb-8" />
                <h2 class="text-3xl text-zinc-600 font-bold uppercase tracking-widest">{{ __('Preparing Orders...') }}
                </h2>
            </div>
        @endif
    </div>



</flux:main>
