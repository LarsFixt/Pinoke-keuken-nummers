<?php

use App\Events\OrderCompleted;
use App\Events\OrderReady;
use App\Events\OrdersUpdated;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\PushSubscription;
use App\Notifications\OrderReadyNotification;
use App\OrderStatus;
use App\Services\PiStatus;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * Tickets come in through the ticket reader, so the numpad is only a fallback.
     */
    public bool $isNumpadVisible = false;

    public function mount(): void
    {
        $this->isNumpadVisible = $this->bridgeOffline;
    }

    public function getListeners()
    {
        return [
            'echo:orders,OrderReady' => '$refresh',
            'echo:orders,OrderCompleted' => '$refresh',
            'echo:orders,OrderReceived' => '$refresh',
            'echo:orders,OrdersUpdated' => '$refresh',
            'echo:kiosk-control,TvStatusUpdated' => '$refresh',
        ];
    }

    public function callOrder(string $number): void
    {
        $number = trim($number);

        $validator = Validator::make(['number' => $number], ['number' => ['required', 'string', 'max:4', 'regex:/^[0-9]+$/', 'not_in:0']]);

        if ($validator->fails() || Order::numberKey($number) === '') {
            return;
        }

        $order = Order::currentOrNewForNumber($number);

        // Prevent duplicate orders with the same number
        if ($order->status === OrderStatus::Ready) {
            Flux::toast(__('An order with this number is already ready.'), variant: 'danger');

            return;
        }

        $this->announceReady($order);
    }

    /**
     * Call an order that came in through a kitchen ticket.
     */
    public function markReady(int $id): void
    {
        $order = Order::find($id);

        if ($order && $order->status === OrderStatus::Pending) {
            $this->announceReady($order);
        }
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

    /**
     * Hide a ticket without a readable number once the kitchen has handled it.
     */
    public function dismissTicket(int $id): void
    {
        KitchenTicket::needsAttention()
            ->whereKey($id)
            ->update(['dismissed_at' => now()]);
    }

    /**
     * Call every order that is in preparation at once.
     */
    public function markAllReady(): void
    {
        $orders = Order::inPreparation()->get();

        foreach ($orders as $order) {
            $order->update(['status' => OrderStatus::Ready]);
            $order->notify(new OrderReadyNotification($order));
        }

        if ($orders->isNotEmpty()) {
            broadcast(new OrdersUpdated(OrdersUpdated::BECAME_READY))->toOthers();
        }

        Flux::modal('confirm-mark-all-ready')->close();
    }

    /**
     * Throw away every order in preparation, with its tickets, e.g. after a test or a cancelled rush.
     */
    public function deleteAllInPreparation(): void
    {
        $orderIds = Order::inPreparation()->pluck('id');

        DB::transaction(function () use ($orderIds): void {
            KitchenTicket::whereIn('order_id', $orderIds)->delete();
            Order::whereIn('id', $orderIds)->delete();
        });

        if ($orderIds->isNotEmpty()) {
            broadcast(new OrdersUpdated(OrdersUpdated::REMOVED))->toOthers();
        }

        Flux::modal('confirm-delete-all-preparing')->close();
    }

    /**
     * Mark every ready order as picked up. They stay under "Recently completed" so a mistake can be undone.
     */
    public function completeAllReady(): void
    {
        $orderIds = Order::ready()->pluck('id');

        DB::transaction(function () use ($orderIds): void {
            Order::whereIn('id', $orderIds)->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);
            PushSubscription::whereIn('order_id', $orderIds)->delete();
        });

        if ($orderIds->isNotEmpty()) {
            broadcast(new OrdersUpdated(OrdersUpdated::REMOVED))->toOthers();
        }

        Flux::modal('confirm-complete-all-ready')->close();
    }

    public function dismissAllTickets(): void
    {
        KitchenTicket::needsAttention()->update(['dismissed_at' => now()]);
    }

    #[Computed]
    public function recentlyCompletedOrders()
    {
        return Order::where('status', OrderStatus::Completed)
            ->where('completed_at', '>', now()->subHours(Order::NUMBER_REUSE_AFTER_HOURS))
            ->latest('completed_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function readyOrders()
    {
        return Order::ready()->withCount('pushSubscriptions')->latest()->get();
    }

    #[Computed]
    public function ordersInPreparation()
    {
        return Order::inPreparation()->with('kitchenTickets')->oldest('updated_at')->oldest('id')->get();
    }

    #[Computed]
    public function ticketsNeedingAttention()
    {
        return KitchenTicket::needsAttention()->latest()->latest('id')->limit(10)->get();
    }

    /**
     * True when the ticket reader has been seen before but has gone quiet.
     */
    #[Computed]
    public function bridgeOffline(): bool
    {
        return app(PiStatus::class)->bridgeWentQuiet();
    }

    /**
     * Whether the number display is on. While it is off, new tickets are not added to "In preparation".
     */
    #[Computed]
    public function displayIsOn(): bool
    {
        return app(PiStatus::class)->displayIsOn();
    }

    private function announceReady(Order $order): void
    {
        $order->status = OrderStatus::Ready;
        $order->save();

        broadcast(new OrderReady($order))->toOthers();
        $order->notify(new OrderReadyNotification($order));
    }
};
?>

<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="xl" level="1">{{ __('Kitchen') }}</flux:heading>
        @unless ($this->displayIsOn)
            <flux:badge color="red" variant="solid" icon="tv">{{ __('Display off') }}</flux:badge>
            <flux:button size="sm" href="{{ route('tv-control') }}">{{ __('Go to TV control') }}</flux:button>
        @endunless
        <flux:spacer />
        <flux:button wire:click="$toggle('isNumpadVisible')" :variant="$isNumpadVisible ? 'filled' : 'outline'"
            icon="calculator">
            {{ $isNumpadVisible ? __('Hide numpad') : __('Numpad') }}
        </flux:button>
    </div>

    @if ($this->bridgeOffline)
        <flux:callout variant="warning" icon="signal-slash" heading="{{ __('Ticket reader offline') }}"
            text="{{ __('New tickets are not coming in automatically. Use the numpad until it is back.') }}" />
    @endif

    <div class="flex flex-col gap-4 md:flex-row md:items-start">
        @if ($isNumpadVisible)
            <!-- Numpad Section -->
            <div class="w-full max-w-sm mx-auto md:w-64 lg:w-72 md:shrink-0 md:mx-0">
                <flux:card class="p-4!" x-data="{ currentNumber: '0' }">
                    <div
                        class="text-center mb-3 h-16 flex items-center justify-center bg-zinc-100 rounded-xl dark:bg-zinc-800">
                        <span class="text-5xl font-black text-zinc-900 dark:text-zinc-100 tracking-wider"
                            x-text="(currentNumber == '0' ? '0...' : currentNumber)">
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        @foreach ([7, 8, 9, 4, 5, 6, 1, 2, 3] as $num)
                            <flux:button @click="if (currentNumber.length < 4) currentNumber += '{{ $num }}'"
                                class="h-14 text-2xl!">
                                {{ $num }}
                            </flux:button>
                        @endforeach

                        <flux:button @click="currentNumber = ''" variant="danger" class="h-14 text-lg!">
                            {{ __('Clear') }}
                        </flux:button>
                        <flux:button @click="if (currentNumber.length < 4) currentNumber += '0'" class="h-14 text-2xl!">
                            0
                        </flux:button>
                        <flux:button @click="currentNumber = currentNumber.slice(0, -1)" variant="primary"
                            color="amber" class="h-14 text-lg!">
                            {{ __('Back') }}
                        </flux:button>
                        <flux:button @click="$wire.callOrder(currentNumber); currentNumber = '0'" variant="primary"
                            color="green" class="h-14 text-lg! col-span-full">
                            {{ __('Call') }}
                        </flux:button>
                    </div>
                </flux:card>
            </div>
        @endif

        <!-- Active Orders Section -->
        <div class="flex-1 min-w-0 grid gap-4 md:grid-cols-3 md:items-start" wire:poll.60s>
            <div class="flex flex-col gap-4 md:col-span-2">
                @if ($this->ticketsNeedingAttention->isNotEmpty())
                    <flux:card class="p-4!">
                        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <flux:heading size="lg">{{ __('Needs a look') }}</flux:heading>
                                <flux:text size="sm">
                                    {{ __('The number on these tickets could not be read. Call them with the numpad.') }}
                                </flux:text>
                            </div>
                            <flux:button size="sm" icon="eye-slash" wire:click="dismissAllTickets">
                                {{ __('Dismiss all') }}
                            </flux:button>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($this->ticketsNeedingAttention as $ticket)
                                <flux:callout wire:key="ticket-attention-{{ $ticket->id }}" variant="warning"
                                    icon="exclamation-triangle">
                                    <flux:callout.text class="whitespace-pre-line text-sm">{{ $ticket->raw_text }}
                                    </flux:callout.text>
                                    <x-slot name="actions">
                                        <flux:button size="sm" wire:click="dismissTicket({{ $ticket->id }})">
                                            {{ __('Dismiss') }}
                                        </flux:button>
                                    </x-slot>
                                </flux:callout>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                <flux:card class="p-4! @container">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <flux:heading size="lg">
                            {{ __('In preparation') }}
                            @if ($this->ordersInPreparation->isNotEmpty())
                                <span
                                    class="text-zinc-500 dark:text-zinc-400">({{ $this->ordersInPreparation->count() }})</span>
                            @endif
                        </flux:heading>
                        @if ($this->ordersInPreparation->isNotEmpty())
                            <div class="flex gap-2">
                                <flux:modal.trigger name="confirm-mark-all-ready">
                                    <flux:button size="sm" variant="primary" color="green" icon="megaphone">
                                        {{ __('All ready') }}
                                    </flux:button>
                                </flux:modal.trigger>
                                <flux:modal.trigger name="confirm-delete-all-preparing">
                                    <flux:button size="sm" variant="danger" icon="trash">{{ __('Delete all') }}
                                    </flux:button>
                                </flux:modal.trigger>
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 @lg:grid-cols-3 @3xl:grid-cols-4 gap-2">
                        @forelse ($this->ordersInPreparation as $order)
                            <flux:card wire:key="preparing-order-{{ $order->id }}" size="sm"
                                wire:click="markReady({{ $order->id }})" role="button" tabindex="0"
                                x-on:keydown.enter="$wire.markReady({{ $order->id }})" @class([
                                    'flex flex-col gap-0.5 p-3! cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/15',
                                    'ring-2 ring-amber-500' => $order->hasUncertainTicket(),
                                ])>
                                <div class="flex items-baseline justify-between gap-2">
                                    <span
                                        class="text-2xl font-black leading-tight text-zinc-900 dark:text-white">{{ $order->number }}</span>
                                    <flux:text size="sm" class="tabular-nums">
                                        {{ $order->orderedAtOrCreatedAt()->timezone(config('app.display_timezone'))->format('H:i') }}
                                    </flux:text>
                                </div>
                                @if ($order->hasUncertainTicket())
                                    <div>
                                        <flux:tooltip :content="__('Check the number on the ticket')">
                                            <flux:badge color="amber" size="sm" icon="exclamation-triangle">
                                                {{ __('Check number') }}
                                            </flux:badge>
                                        </flux:tooltip>
                                    </div>
                                @endif
                                @foreach ($order->kitchenTickets as $ticket)
                                    @foreach ($ticket->items as $item)
                                        <flux:text size="sm" variant="strong" class="leading-snug">
                                            {{ $item['qty'] }}× {{ $item['name'] }}</flux:text>
                                        @foreach ($item['notes'] as $note)
                                            <flux:text size="sm" class="pl-3 leading-snug">{{ $note }}
                                            </flux:text>
                                        @endforeach
                                    @endforeach
                                @endforeach
                            </flux:card>
                        @empty
                            <flux:text class="col-span-full text-center py-6">
                                {{ $this->displayIsOn ? __('No orders in preparation.') : __('The display is off, so new tickets are not shown here.') }}
                            </flux:text>
                        @endforelse
                    </div>
                </flux:card>
            </div>

            <div class="flex flex-col gap-4">
                <flux:card class="p-4! @container">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <flux:heading size="lg">{{ __('Ready orders') }}</flux:heading>
                        @if ($this->readyOrders->isNotEmpty())
                            <flux:modal.trigger name="confirm-complete-all-ready">
                                <flux:button size="sm" icon="check-circle">{{ __('Remove all') }}</flux:button>
                            </flux:modal.trigger>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 @xs:grid-cols-3 gap-2">
                        @forelse($this->readyOrders as $order)
                            <div wire:key="ready-order-{{ $order->id }}" x-data="{
                                elapsed: {{ max(0, $order->updated_at->diffInSeconds(now())) }},
                                timer: null,
                                get colorClasses() {
                                    if (this.elapsed >= 210) return '!bg-red-500 !text-white hover:!bg-red-600';
                                    if (this.elapsed >= 120) return '!bg-orange-500 !text-white hover:!bg-orange-600';
                                    if (this.elapsed >= 90) return '!bg-amber-500 !text-white hover:!bg-amber-600';
                                    if (this.elapsed >= 60) return '!bg-yellow-400 !text-yellow-900 hover:!bg-yellow-500';
                                    if (this.elapsed >= 30) return '!bg-lime-400 !text-lime-900 hover:!bg-lime-500';
                                    return '!bg-green-500 !text-white hover:!bg-green-600';
                                },
                                init() {
                                    // If it loads already past 4 minutes, complete it immediately
                                    if (this.elapsed >= 360) {
                                        $wire.completeOrder({{ $order->id }});
                                        return;
                                    }
                            
                                    // Tick every 1 second
                                    this.timer = setInterval(() => {
                                        this.elapsed += 1;
                                        if (this.elapsed >= 240) {
                                            clearInterval(this.timer);
                                            $wire.completeOrder({{ $order->id }});
                                        }
                                    }, 1000);
                                }
                            }">
                                <flux:button wire:click="completeOrder({{ $order->id }})" variant="primary"
                                    icon:trailing="{{ $order->push_subscriptions_count > 0 ? 'device-phone-mobile' : '' }}"
                                    title="{{ $order->push_subscriptions_count > 0 ? __('Push linked') : '' }}"
                                    class="w-full h-14 transition-colors duration-500 ease-in-out !border-none"
                                    x-bind:class="colorClasses">
                                    <span class="flex flex-col items-center leading-none">
                                        <span class="text-2xl font-black">{{ $order->number }}</span>
                                        <span class="text-xs font-medium tabular-nums opacity-80">
                                            {{ $order->orderedAtOrCreatedAt()->timezone(config('app.display_timezone'))->format('H:i') }}
                                        </span>
                                    </span>
                                </flux:button>
                            </div>
                        @empty
                            <flux:text class="col-span-full text-center py-6">
                                {{ __('No orders currently ready.') }}
                            </flux:text>
                        @endforelse
                    </div>
                </flux:card>

                @if ($this->recentlyCompletedOrders->isNotEmpty())
                    <flux:card class="p-4!">
                        <flux:heading size="lg">{{ __('Recently completed') }}</flux:heading>
                        <flux:text size="sm" class="mb-3">
                            {{ __('Tap to re-add if you completed the wrong order.') }}</flux:text>

                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->recentlyCompletedOrders as $order)
                                <flux:button wire:click="reactivateOrder({{ $order->id }})" variant="filled"
                                    class="h-12 min-w-16">
                                    <span class="flex flex-col items-center leading-none">
                                        <span class="text-xl font-black">{{ $order->number }}</span>
                                        <span class="text-xs font-medium tabular-nums opacity-80">
                                            {{ $order->orderedAtOrCreatedAt()->timezone(config('app.display_timezone'))->format('H:i') }}
                                        </span>
                                    </span>
                                </flux:button>
                            @endforeach
                        </div>
                    </flux:card>
                @endif
            </div>
        </div>
    </div>

    {{-- Confirmations for the bulk actions: big buttons for a busy kitchen tablet --}}
    <flux:modal name="confirm-mark-all-ready" class="md:w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Call all orders in preparation?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ trans_choice(':count order will be shown as ready and its customer notified.|:count orders will be shown as ready and their customers notified.', $this->ordersInPreparation->count()) }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" color="green" icon="megaphone" wire:click="markAllReady">
                    {{ __('All ready') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-delete-all-preparing" class="md:w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete all orders in preparation?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('They disappear from the kitchen and the display without notifying anyone. This cannot be undone.') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" icon="trash" wire:click="deleteAllInPreparation">
                    {{ __('Delete all') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-complete-all-ready" class="md:w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Remove all ready orders?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('They are marked as picked up. You can still re-add them under "Recently completed".') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" icon="check-circle" wire:click="completeAllReady">
                    {{ __('Remove all') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
