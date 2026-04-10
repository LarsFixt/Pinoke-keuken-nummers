<?php

use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component {
    public string $currentNumber = '';

    public bool $isWatching = false;

    public bool $orderReady = false;

    public string $kitchenStatus = '';

    public function mount(): void
    {
        $this->kitchenStatus = cache('kitchen_status', '');
    }

    public function getListeners(): array
    {
        return [
            'echo:orders,OrderReady' => 'checkOrderReady',
            'echo:orders,KitchenStatusUpdated' => 'updateStatus',
        ];
    }

    public function updateStatus(array $event): void
    {
        $this->kitchenStatus = $event['message'] ?? '';
    }

    public function appendNumber(string $num): void
    {
        if (!$this->isWatching && strlen($this->currentNumber) < 4) {
            $this->currentNumber .= $num;
        }
    }

    public function clearNumber(): void
    {
        if (!$this->isWatching) {
            $this->currentNumber = '';
        }
    }

    public function startWatching(): void
    {
        if (empty($this->currentNumber)) {
            return;
        }

        $this->isWatching = true;
        $this->orderReady = Order::ready()->where('number', $this->currentNumber)->exists();
    }

    public function stopTracking(): void
    {
        $this->currentNumber = '';
        $this->isWatching = false;
        $this->orderReady = false;
    }

    public function checkOrderReady(array $event): void
    {
        if ($this->isWatching && isset($event['order']['number']) && (string) $event['order']['number'] === (string) $this->currentNumber) {
            $this->orderReady = true;
        }
    }
};
?>

<flux:main>
    <div class="flex flex-col items-center justify-center" x-data="{
        notified: false,
        async requestNotificationPermission() {
            if ('Notification' in window && Notification.permission === 'default') {
                await Notification.requestPermission();
            }
        },
        sendNotification(number) {
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('{{ __('Your order is ready!') }}', {
                    body: '{{ __('Number') }} ' + number + ' - {{ __('Come pick up your food!') }}',
                    icon: '/favicon.ico',
                });
            }
        },
        playSound() {
            new Audio('/sound/bell.mp3').play();
        },
    }"
        x-effect="if ($wire.orderReady && !notified) { notified = true; playSound(); sendNotification($wire.currentNumber); }">
        <div class="text-center mb-8 w-full">
            <h1 class="text-3xl font-black tracking-tight uppercase">
                {{ __('Track your order') }}
            </h1>
            <flux:text class="text-base mt-2">
                {{ __('Enter your number and we will let you know when your food is ready.') }}
            </flux:text>

            @if ($kitchenStatus)
                <flux:callout variant="warning" icon="megaphone" class="my-2" heading="{{ $kitchenStatus }}" />
            @endif
        </div>

        @if ($orderReady)
            {{-- Order ready --}}
            <div class="w-full text-center">
                <flux:card class="flex flex-col items-center justify-center gap-4 py-10">
                    <flux:icon.check-circle class="w-20 h-20 text-green-500" />
                    <flux:text class="text-8xl font-black tracking-tighter">
                        {{ $currentNumber }}
                    </flux:text>
                    <flux:heading size="xl" class="text-green-500 uppercase font-bold">
                        {{ __('Your order is ready!') }}
                    </flux:heading>
                    <flux:text class="text-lg">
                        {{ __('Come pick up your food!') }}
                    </flux:text>
                    <flux:button wire:click="stopTracking" variant="filled" class="mt-4 w-full">
                        {{ __('Track another order') }}
                    </flux:button>
                </flux:card>
            </div>
        @elseif ($isWatching)
            {{-- Watching state --}}

            <div class="w-full text-center">
                <flux:card class="flex flex-col items-center justify-center gap-4 py-10">
                    <flux:icon.clock class="w-16 h-16 animate-pulse" />
                    <flux:text class="text-8xl font-black tracking-tighter">
                        {{ $currentNumber }}
                    </flux:text>
                    <flux:text class="text-lg uppercase tracking-widest font-semibold">
                        {{ __('Preparing...') }}
                    </flux:text>
                    <flux:text class="text-sm">
                        {{ __('We will notify you as soon as it is ready.') }}
                    </flux:text>
                    <flux:button wire:click="stopTracking" class="mt-4 w-full" size="sm">
                        {{ __('Cancel') }}
                    </flux:button>
                </flux:card>

                <div x-cloak x-show="'Notification' in window && Notification.permission === 'default'" class="mt-4">
                    <flux:button @click="requestNotificationPermission()" variant="subtle" class="w-full text-sm"
                        icon="bell" icon-position="left">
                        {{ __('Allow notifications') }}
                    </flux:button>
                </div>
            </div>
        @else
            {{-- Number entry --}}
            <div class="w-full">
                <flux:card>
                    <div
                        class="text-center mb-6 h-20 flex items-center justify-center bg-gray-100 rounded-xl dark:bg-zinc-800">
                        <span class="text-5xl font-black text-gray-900 dark:text-gray-100 tracking-wider">
                            {{ $currentNumber ?: '...' }}
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        @foreach ([7, 8, 9, 4, 5, 6, 1, 2, 3] as $num)
                            <flux:button wire:click="appendNumber('{{ $num }}')" class="h-16 text-2xl!">
                                {{ $num }}
                            </flux:button>
                        @endforeach

                        <flux:button wire:click="clearNumber" variant="ghost" class="h-16 text-lg!">
                            {{ __('Clear') }}
                        </flux:button>

                        <flux:button wire:click="appendNumber('0')" class="h-16 text-2xl!">
                            0
                        </flux:button>

                        <div></div>
                    </div>

                    <flux:button variant="primary" class="w-full mt-4" x-bind:disabled="!$wire.currentNumber.length"
                        @click="requestNotificationPermission(); $wire.startWatching();">
                        {{ __('Follow my order') }}
                    </flux:button>
                </flux:card>
            </div>
        @endif
    </div>
</flux:main>
