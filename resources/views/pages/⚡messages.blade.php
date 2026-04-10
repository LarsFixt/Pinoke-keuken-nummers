<?php

use App\Events\KitchenStatusUpdated;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $statusMessage = '';

    public string $customMessage = '';

    public function mount(): void
    {
        $this->statusMessage = cache('kitchen_status', '');
    }

    public function setStatus(string $message): void
    {
        $this->statusMessage = $message;
        $this->customMessage = '';
        cache(['kitchen_status' => $message], now()->addHours(8));
        broadcast(new KitchenStatusUpdated($message));
    }

    public function sendCustomMessage(): void
    {
        $message = trim($this->customMessage);

        if (empty($message)) {
            return;
        }

        $this->setStatus($message);
    }

    public function clearStatus(): void
    {
        $this->setStatus('');
    }

    #[Computed]
    public function presetMessages(): array
    {
        return [__('The kitchen is really busy right now, please be patient!'), __('Running a bit behind, thanks for your patience!'), __("We'll be right back in a moment."), __("Today's special is almost sold out!")];
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="relative mb-6 w-full">
        <flux:heading size="xl" level="1">{{ __('Messages') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('This message is shown on customer screens') }}
        </flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    {{-- Active message --}}
    @if ($statusMessage)
        <div
            class="p-4 bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700 rounded-xl flex items-start gap-3">
            <flux:icon.megaphone class="w-5 h-5 shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" />
            <flux:text class="flex-1 text-amber-800 dark:text-amber-300 font-medium">{{ $statusMessage }}
            </flux:text>
        </div>
        <flux:button wire:click="clearStatus" variant="danger" class="w-full">
            {{ __('Remove message') }}
        </flux:button>
        <flux:separator />
    @endif

    {{-- Custom message --}}
    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Custom message') }}</flux:heading>
        <div class="flex gap-2">
            <flux:input wire:model="customMessage" wire:keydown.enter="sendCustomMessage"
                placeholder="{{ __('Type a message...') }}" class="flex-1" />
            <flux:button wire:click="sendCustomMessage" variant="primary">
                {{ __('Send') }}
            </flux:button>
        </div>
    </div>

    <flux:separator />

    {{-- Presets --}}
    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Quick messages') }}</flux:heading>
        <div class="flex flex-col gap-2">
            @foreach ($this->presetMessages as $preset)
                <flux:button wire:click="setStatus('{{ addslashes($preset) }}')"
                    variant="{{ $statusMessage === $preset ? 'primary' : 'subtle' }}" class="text-left justify-start!">
                    {{ $preset }}
                </flux:button>
            @endforeach
        </div>
    </div>
</div>
