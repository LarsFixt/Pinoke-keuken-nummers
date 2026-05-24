<?php

use App\Events\OrderCompleted;
use App\Events\OrderReady;
use App\Models\Order;
use App\OrderStatus;
use App\Notifications\OrderReadyNotification;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public function getListeners()
    {
        return [
            'echo:orders,OrderReady' => '$refresh',
            'echo:orders,OrderCompleted' => '$refresh',
        ];
    }

    public function callOrder(string $number): void
    {
        $number = trim($number);

        $validator = Validator::make(['number' => $number], ['number' => ['required', 'string', 'max:4', 'regex:/^[0-9]+$/']]);

        if ($validator->fails()) {
            return;
        }

        // Prevent duplicate orders with the same number
        $exists = Order::ready()->where('number', $number)->exists();
        if ($exists) {
            Flux::toast(__('An order with this number is already ready.'), variant: 'danger');

            return;
        }

        $order = Order::updateOrCreate(['number' => $number], ['status' => OrderStatus::Ready]);

        broadcast(new OrderReady($order))->toOthers();
        $order->notify(new OrderReadyNotification($order));
    }

    public function completeOrder(int $id): void
    {
        $order = Order::find($id);

        if ($order && $order->status === OrderStatus::Ready) {
            $order->markCompleted();
            broadcast(new OrderCompleted($order))->toOthers();
            $order->pushSubscriptions()->delete();
        }
    }

    public function reactivateOrder(int $id): void
    {
        $order = Order::find($id);

        if ($order && $order->status === OrderStatus::Completed) {
            $order->update(['status' => OrderStatus::Ready]);
            broadcast(new OrderReady($order))->toOthers();
        }
    }

    #[Computed]
    public function recentlyCompletedOrders()
    {
        return Order::where('status', OrderStatus::Completed)->latest('updated_at')->limit(5)->get();
    }

    #[Computed]
    public function readyOrders()
    {
        return Order::ready()->withCount('pushSubscriptions')->latest()->get();
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
            <flux:card x-data="{ currentNumber: '' }">
                <div
                    class="text-center mb-6 h-24 flex items-center justify-center bg-gray-100 rounded-xl dark:bg-zinc-800">
                    <span class="text-6xl font-black text-gray-900 dark:text-gray-100 tracking-wider"
                        x-text="currentNumber || '....'">
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    @foreach ([7, 8, 9, 4, 5, 6, 1, 2, 3] as $num)
                        <flux:button @click="if (currentNumber.length < 4) currentNumber += '{{ $num }}'"
                            class="h-20 text-3xl!">
                            {{ $num }}
                        </flux:button>
                    @endforeach

                    <flux:button @click="currentNumber = ''" variant="danger" class="h-20 text-xl!">
                        {{ __('Clear') }}
                    </flux:button>
                    <flux:button @click="if (currentNumber.length < 4) currentNumber += '0'" class="h-20 text-3xl!">
                        0
                    </flux:button>
                    <flux:button @click="$wire.callOrder(currentNumber); currentNumber = ''" variant="primary"
                        color="green" class="h-20 text-xl!">
                        {{ __('Call') }}
                    </flux:button>
                </div>
            </flux:card>
        </div>

        <!-- Active Orders Section -->
        <div class="flex-1">
            <flux:card>
                <flux:heading size="xl">{{ __('Ready orders') }}</flux:heading>
                <flux:subheading class="mb-4">{{ __('Tap an order to mark it as complete.') }}</flux:subheading>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    @forelse($this->readyOrders as $order)
                        <flux:button wire:click="completeOrder({{ $order->id }})" variant="primary" color="green"
                            icon:trailing="{{ $order->push_subscriptions_count > 0 ? 'device-phone-mobile' : '' }}"
                            title="{{ $order->push_subscriptions_count > 0 ? __('Push linked') : '' }}"
                            class="w-full h-16 text-3xl! font-black!">
                            {{ $order->number }}
                        </flux:button>
                    @empty
                        <flux:text size="lg" class="col-span-full text-center py-8">
                            {{ __('No orders currently ready.') }}
                        </flux:text>
                    @endforelse
                </div>
            </flux:card>

            @if ($this->recentlyCompletedOrders->isNotEmpty())
                <flux:card class="mt-4">
                    <flux:heading size="xl" class="mb-4">{{ __('Recently completed') }}</flux:heading>
                    <flux:subheading class="mb-4">
                        {{ __('Tap to re-add if you completed the wrong order.') }}</flux:subheading>

                    <div class="flex flex-wrap gap-4">
                        @foreach ($this->recentlyCompletedOrders as $order)
                            <flux:button wire:click="reactivateOrder({{ $order->id }})" variant="filled"
                                class="h-16 text-3xl! font-black!">
                                {{ $order->number }}
                            </flux:button>
                        @endforeach
                    </div>
                </flux:card>
            @endif
        </div>
    </div>
</div>
