<div class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8 flex gap-8 flex-col lg:flex-row">
    <!-- Numpad Section -->
    <div class="flex-1 w-full max-w-sm mx-auto">
        <flux:card>
            <div class="text-center mb-6 h-24 flex items-center justify-center bg-gray-100 rounded-xl dark:bg-zinc-800">
                <span class="text-6xl font-black text-gray-900 dark:text-gray-100 tracking-wider">
                    {{ $currentNumber ?: '...' }}
                </span>
            </div>

            <div class="grid grid-cols-3 gap-3">
                @foreach([7, 8, 9, 4, 5, 6, 1, 2, 3] as $num)
                    <button wire:click="appendNumber('{{ $num }}')" class="h-20 text-3xl font-bold bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-accent active:bg-zinc-100 transition">
                        {{ $num }}
                    </button>
                @endforeach
                
                <button wire:click="clearNumber" class="h-20 text-xl font-bold text-red-500 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 transition">
                    CLEAR
                </button>
                <button wire:click="appendNumber('0')" class="h-20 text-3xl font-bold bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-accent transition">
                    0
                </button>
                <button wire:click="callOrder" class="h-20 text-xl font-bold text-white bg-emerald-500 hover:bg-emerald-600 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 transition shadow">
                    CALL
                </button>
            </div>
        </flux:card>
    </div>

    <!-- Active Orders Section -->
    <div class="flex-1">
        <flux:card>
            <flux:heading size="xl" class="mb-4">Ready Orders</flux:heading>
            
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                @forelse($readyOrders as $order)
                    <button wire:click="completeOrder({{ $order->id }})" class="p-4 bg-lime-100 dark:bg-lime-900/30 border border-lime-200 dark:border-lime-800 rounded-xl text-center hover:bg-lime-200 dark:hover:bg-lime-900/50 transition relative group group-active:scale-95">
                        <div class="text-3xl font-black text-lime-800 dark:text-lime-300">
                            {{ $order->number }}
                        </div>
                        <div class="text-xs text-lime-600 dark:text-lime-500 mt-1 uppercase font-semibold">
                            Tap to Complete
                        </div>
                    </button>
                @empty
                    <div class="col-span-full text-center py-8 text-zinc-500">
                        No orders currently ready.
                    </div>
                @endforelse
            </div>
        </flux:card>
    </div>
</div>
