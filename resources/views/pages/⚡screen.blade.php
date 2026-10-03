<?php

use App\Events\TvStatusUpdated;
use App\Models\KitchenTicket;
use App\Services\PiStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public $state = ['status' => 'on'];

    public function mount()
    {
        // Fetch the current state from the cache on component load
        $this->state['status'] = Cache::get('kiosk_tv_status', 'on');
    }

    public function toggle(string $newStatus)
    {
        if (! in_array($newStatus, ['on', 'off'], true)) {
            return;
        }

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

    /**
     * Restart the ticket reader program. The bridge picks this up with its next heartbeat.
     */
    public function restartBridge(): void
    {
        app(PiStatus::class)->queueBridgeCommand('restart');
        Flux::toast(__('The ticket reader restarts within a minute.'));
    }

    public function rebootBridge(): void
    {
        if (! auth()->user()->is_super_admin) {
            return;
        }

        app(PiStatus::class)->queueBridgeCommand('reboot');
        Flux::toast(__('The ticket reader Pi reboots within a minute.'));
    }

    public function cancelBridgeCommand(): void
    {
        Cache::forget(PiStatus::BRIDGE_COMMAND_KEY);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function kiosk(): ?array
    {
        return app(PiStatus::class)->kiosk();
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function bridge(): ?array
    {
        return app(PiStatus::class)->bridge();
    }

    #[Computed]
    public function kioskOnline(): bool
    {
        return app(PiStatus::class)->isOnline($this->kiosk);
    }

    #[Computed]
    public function bridgeOnline(): bool
    {
        return app(PiStatus::class)->isOnline($this->bridge);
    }

    #[Computed]
    public function pendingBridgeCommand(): ?string
    {
        return app(PiStatus::class)->pendingBridgeCommand();
    }

    #[Computed]
    public function ticketsToday(): int
    {
        return KitchenTicket::where('created_at', '>=', today())->count();
    }

    /**
     * The TV answers CEC with its real power state; it disagreeing with the chosen state means CEC is not working.
     */
    #[Computed]
    public function tvDisagrees(): bool
    {
        $power = $this->kiosk['tv_power'] ?? null;

        return $this->kioskOnline
            && in_array($power, ['on', 'standby'], true)
            && ($power === 'on') !== ($this->state['status'] === 'on');
    }

    public function lastSeen(?array $status): string
    {
        return isset($status['reported_at'])
            ? CarbonImmutable::createFromTimestamp($status['reported_at'])->diffForHumans()
            : __('never');
    }

    public function uptime(?array $status): string
    {
        return isset($status['uptime_seconds'])
            ? CarbonImmutable::now()->subSeconds($status['uptime_seconds'])->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, parts: 2)
            : '–';
    }
};
?>

<div wire:poll.15s>

    <div class="hidden md:block relative mb-6 w-full">
        <flux:heading size="xl" level="1">{{ __('TV Control') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('Manage your TV screens') }}
        </flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        {{-- Kiosk Pi: drives the TV above the pickup counter --}}
        <flux:card data-test="kiosk-card">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ __('TV Control') }}</flux:heading>
                    <flux:subheading>{{ __('Control the screen above the pickup counter') }}</flux:subheading>
                </div>
                @if ($this->kioskOnline)
                    <flux:badge color="green" icon="signal">{{ __('Online') }}</flux:badge>
                @else
                    <flux:badge color="red" icon="signal-slash">{{ __('Offline') }}</flux:badge>
                @endif
            </div>

            <div class="mt-6 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
                <div>
                    <flux:text size="sm">{{ __('TV (via CEC)') }}</flux:text>
                    @switch($this->kiosk['tv_power'] ?? null)
                        @case('on')
                            <flux:badge color="green" size="sm">{{ __('On') }}</flux:badge>
                            @break
                        @case('standby')
                            <flux:badge size="sm">{{ __('Standby') }}</flux:badge>
                            @break
                        @case('in transition from standby to on')
                        @case('in transition from on to standby')
                            <flux:badge color="sky" size="sm">{{ __('Switching') }}</flux:badge>
                            @break
                        @default
                            <flux:badge color="amber" size="sm">{{ __('Unknown') }}</flux:badge>
                    @endswitch
                </div>
                <div>
                    <flux:text size="sm">{{ __('Browser') }}</flux:text>
                    @if ($this->kiosk['browser_running'] ?? false)
                        <flux:badge color="green" size="sm">{{ __('Running') }}</flux:badge>
                    @else
                        <flux:badge color="red" size="sm">{{ __('Not running') }}</flux:badge>
                    @endif
                </div>
                <div>
                    <flux:text size="sm">{{ __('Last seen') }}</flux:text>
                    <flux:text variant="strong">{{ $this->lastSeen($this->kiosk) }}</flux:text>
                </div>
                <div>
                    <flux:text size="sm">{{ __('Device') }}</flux:text>
                    <flux:text variant="strong">{{ $this->kiosk['hostname'] ?? '–' }}</flux:text>
                </div>
                <div>
                    <flux:text size="sm">{{ __('IP address') }}</flux:text>
                    <flux:text variant="strong">{{ $this->kiosk['ip'] ?? '–' }}</flux:text>
                </div>
                <div>
                    <flux:text size="sm">{{ __('Up for') }}</flux:text>
                    <flux:text variant="strong">{{ $this->uptime($this->kiosk) }}</flux:text>
                </div>
            </div>

            @if ($this->kioskOnline && ($this->kiosk['cec_error'] ?? null))
                <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6"
                    heading="{{ __('The TV cannot be reached over HDMI-CEC') }}"
                    text="{{ $this->kiosk['cec_error'] }}" />
            @elseif ($this->tvDisagrees)
                <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6"
                    heading="{{ __('The TV does not follow the chosen setting') }}"
                    text="{{ __('Check that HDMI-CEC is enabled on the TV (Anynet+, Bravia Sync, SimpLink) and that the HDMI cable is in a CEC port.') }}" />
            @endif

            <div class="mt-6 flex flex-wrap items-center gap-2">
                <flux:button wire:click="toggle('on')"
                    variant="{{ $this->state['status'] === 'on' ? 'primary' : 'outline' }}" icon="check-circle">
                    {{ __('Screen On') }}
                </flux:button>

                <flux:button wire:click="toggle('off')"
                    variant="{{ $this->state['status'] === 'off' ? 'danger' : 'outline' }}" icon="power">
                    {{ __('Screen Off') }}
                </flux:button>

                <!-- Only visible to Super Admins -->
                @if (auth()->user()->is_super_admin)
                    <flux:separator vertical class="mx-2" />

                    <flux:button wire:click="reboot" variant="subtle" icon="arrow-path"
                        wire:confirm="{{ __('Are you sure you want to reboot the kitchen display?') }}">
                        {{ __('Reboot player') }}
                    </flux:button>
                @endif
            </div>
        </flux:card>

        {{-- Bridge Pi: reads the kitchen tickets on the switch mirror port --}}
        <flux:card data-test="bridge-card">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ __('Ticket reader') }}</flux:heading>
                    <flux:subheading>{{ __('Reads the kitchen tickets from the printer network') }}</flux:subheading>
                </div>
                @if ($this->bridgeOnline)
                    <flux:badge color="green" icon="signal">{{ __('Online') }}</flux:badge>
                @else
                    <flux:badge color="red" icon="signal-slash">{{ __('Offline') }}</flux:badge>
                @endif
            </div>

            <div class="mt-6 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
                <div>
                    <flux:text size="sm">{{ __('Printer network') }}</flux:text>
                    @if (($this->bridge['iface_up'] ?? null) === false)
                        <flux:badge color="red" size="sm">{{ __('Cable unplugged') }}</flux:badge>
                    @elseif (($this->bridge['capturing'] ?? null) === false)
                        <flux:badge color="red" size="sm">{{ __('Not listening') }}</flux:badge>
                    @elseif ($this->bridge['capturing'] ?? false)
                        <flux:badge color="green" size="sm">{{ __('Listening') }}</flux:badge>
                    @else
                        <flux:badge color="amber" size="sm">{{ __('Unknown') }}</flux:badge>
                    @endif
                </div>
                <div>
                    <flux:text size="sm">{{ __('Tickets today') }}</flux:text>
                    <flux:text variant="strong">{{ $this->ticketsToday }}</flux:text>
                </div>
                <div>
                    <flux:text size="sm">{{ __('Waiting to send') }}</flux:text>
                    <flux:text variant="strong">{{ $this->bridge['spool_pending'] ?? '–' }}</flux:text>
                </div>
                <div>
                    <flux:text size="sm">{{ __('Last seen') }}</flux:text>
                    <flux:text variant="strong">{{ $this->lastSeen($this->bridge) }}</flux:text>
                </div>
                <div>
                    <flux:text size="sm">{{ __('Device') }}</flux:text>
                    <flux:text variant="strong">{{ $this->bridge['hostname'] ?? '–' }}</flux:text>
                </div>
                <div>
                    <flux:text size="sm">{{ __('Up for') }}</flux:text>
                    <flux:text variant="strong">{{ $this->uptime($this->bridge) }}</flux:text>
                </div>
            </div>

            @if ($this->bridgeOnline && ($this->bridge['spool_failed'] ?? 0) > 0)
                <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6"
                    heading="{{ trans_choice(':count ticket was refused by the app|:count tickets were refused by the app', $this->bridge['spool_failed']) }}"
                    text="{{ __('They are kept on the Pi in spool/failed for inspection.') }}" />
            @endif

            @if ($this->pendingBridgeCommand)
                <flux:callout variant="secondary" icon="clock" class="mt-6"
                    heading="{{ $this->pendingBridgeCommand === 'reboot' ? __('Reboot requested, waiting for the Pi…') : __('Restart requested, waiting for the Pi…') }}">
                    <x-slot name="actions">
                        <flux:button size="sm" wire:click="cancelBridgeCommand">{{ __('Cancel') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif

            <div class="mt-6 flex flex-wrap items-center gap-2">
                <flux:button wire:click="restartBridge" variant="outline" icon="arrow-path"
                    wire:confirm="{{ __('Restart the ticket reader? Tickets printed during the few seconds it restarts are missed.') }}">
                    {{ __('Restart reader') }}
                </flux:button>

                @if (auth()->user()->is_super_admin)
                    <flux:button wire:click="rebootBridge" variant="subtle" icon="power"
                        wire:confirm="{{ __('Reboot the ticket reader Pi? Tickets printed during the minute it is offline are missed.') }}">
                        {{ __('Reboot Pi') }}
                    </flux:button>
                @endif
            </div>
        </flux:card>
    </div>
</div>
