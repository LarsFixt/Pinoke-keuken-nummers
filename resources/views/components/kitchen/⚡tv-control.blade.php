<?php

use Livewire\Component;
use App\Events\TvStatusUpdated;

new class extends Component {
    public $state = ['status' => 'on'];

    public function mount()
    {
        // Fetch the current state from the cache on component load
        $this->state['status'] = Cache::get('kiosk_tv_status', 'on');
    }

    public function toggle(string $newStatus)
    {
        $this->state['status'] = $newStatus;

        // Store in cache so the Pi knows the state if it reboots
        Cache::put('kiosk_tv_status', $newStatus);

        // Instantly push the event via Reverb to the Raspberry Pi
        broadcast(new TvStatusUpdated($newStatus));
    }

    public function reboot()
    {
        if (auth()->user()->is_super_admin) {
            // Store in cache so the Pi knows the state if it reboots
            Cache::put('kiosk_tv_status', 'on');

            // Instantly push the event via Reverb to the Raspberry Pi
            broadcast(new TvStatusUpdated('reboot'));
        }
    }
};
?>

<div>
    <flux:card>
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="lg">TV Display</flux:heading>
                <flux:subheading>Control the physical screen in the kitchen</flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                <flux:button wire:click="toggle('on')"
                    variant="{{ $this->state['status'] === 'on' ? 'primary' : 'outline' }}" icon="check-circle">
                    Screen On
                </flux:button>

                <flux:button wire:click="toggle('off')"
                    variant="{{ $this->state['status'] === 'off' ? 'danger' : 'outline' }}" icon="power">
                    Screen Off
                </flux:button>

                <!-- Only visible to Super Admins -->
                @if (auth()->user()->is_super_admin)
                    <flux:separator vertical class="mx-2" />

                    <flux:button wire:click="reboot" variant="subtle" icon="arrow-path"
                        wire:confirm="Are you sure you want to reboot the kitchen display?">
                        Reboot player
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:card>
</div>
