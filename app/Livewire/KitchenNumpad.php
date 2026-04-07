<?php

namespace App\Livewire;

use App\Events\OrderCompleted;
use App\Events\OrderReady;
use App\Models\Order;
use Livewire\Component;

class KitchenNumpad extends Component
{
    public string $currentNumber = '';

    public function getListeners()
    {
        return [
            'echo:orders,OrderCompleted' => '$refresh',
            'echo:orders,OrderReady' => '$refresh',
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

    public function render()
    {
        return view('livewire.kitchen-numpad', [
            'readyOrders' => Order::ready()->latest()->get(),
        ])->layout('layouts.app');
    }
}
