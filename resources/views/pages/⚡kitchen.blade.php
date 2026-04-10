<?php

use App\Events\OrderCompleted;
use App\Events\OrderReady;
use App\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $currentNumber = '';

    public function getListeners()
    {
        return [
            'echo:orders,OrderReady' => '$refresh',
            'echo:orders,OrderCompleted' => '$refresh',
        ];
    }

    public function appendNumber(string $num): void
    {
        if (strlen($this->currentNumber) < 4) {
            $this->currentNumber .= $num;
        }
    }

    public function clearNumber(): void
    {
        $this->currentNumber = '';
    }

    public function callOrder(): void
    {
        if (empty($this->currentNumber)) {
            return;
        }

        $order = Order::create([
            'number' => $this->currentNumber,
            'status' => 'ready',
        ]);

        broadcast(new OrderReady($order))->toOthers();

        $this->currentNumber = '';
    }

    public function completeOrder(int $id): void
    {
        $order = Order::find($id);

        if ($order) {
            $order->markCompleted();
            broadcast(new OrderCompleted($order))->toOthers();
        }
    }

    #[Computed]
    public function readyOrders()
    {
        return Order::ready()->latest()->get();
    }
};
?>

<div>
    <div class="hidden md:block relative mb-6 w-full">
        <flux:heading size="xl" level="1">{{ __('Kitchen') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('Manage your kitchen orders') }}
        </flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <div class="flex gap-6 flex-col lg:flex-row">
        <!-- Numpad Section -->
        <div class="flex-1 w-full max-w-sm mx-auto">
            <flux:card>
                <div
                    class="text-center mb-6 h-24 flex items-center justify-center bg-gray-100 rounded-xl dark:bg-zinc-800">
                    <span class="text-6xl font-black text-gray-900 dark:text-gray-100 tracking-wider">
                        {{ $currentNumber ?: '...' }}
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    @foreach ([7, 8, 9, 4, 5, 6, 1, 2, 3] as $num)
                        <flux:button wire:click="appendNumber('{{ $num }}')" class="h-20 text-3xl!">
                            {{ $num }}
                        </flux:button>
                    @endforeach

                    <flux:button wire:click="clearNumber" variant="danger" class="h-20 text-xl!">
                        {{ __('Clear') }}
                    </flux:button>
                    <flux:button wire:click="appendNumber('0')" class="h-20 text-3xl!">
                        0
                    </flux:button>
                    <flux:button wire:click="callOrder" variant="primary" color="green" class="h-20 text-xl!">
                        {{ __('Call') }}
                    </flux:button>
                </div>
            </flux:card>
        </div>

        <!-- Active Orders Section -->
        <div class="flex-1">
            <flux:card>
                <flux:heading size="xl" class="mb-4">{{ __('Ready orders') }}</flux:heading>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    @forelse($this->readyOrders as $order)
                        <button wire:click="completeOrder({{ $order->id }})"
                            class="p-4 bg-lime-100 dark:bg-lime-900/30 border border-lime-200 dark:border-lime-800 rounded-xl text-center hover:bg-lime-200 dark:hover:bg-lime-900/50 transition group-active:scale-95">
                            <div class="text-3xl font-black text-lime-800 dark:text-lime-300">
                                {{ $order->number }}
                            </div>
                            <div class="text-xs text-lime-600 dark:text-lime-500 mt-1 uppercase font-semibold">
                                {{ __('Tap to complete') }}
                            </div>
                        </button>
                    @empty
                        <div class="col-span-full text-center py-8 text-zinc-500">
                            {{ __('No orders currently ready.') }}
                        </div>
                    @endforelse
                </div>
            </flux:card>
        </div>
    </div>
</div>
