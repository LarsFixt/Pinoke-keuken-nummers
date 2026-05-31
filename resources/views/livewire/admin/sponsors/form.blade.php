<form wire:submit="save" class="space-y-4">
    <flux:field>
        <flux:label>{{ __('Name') }}</flux:label>
        <flux:input wire:model="name" />
        <flux:error name="name" />
    </flux:field>

    <flux:field>
        <flux:label>{{ __('Title') }}</flux:label>
        <flux:input wire:model="title" />
        <flux:error name="title" />
    </flux:field>

    <flux:field>
        <flux:label>{{ __('Description') }}</flux:label>
        <flux:textarea wire:model="description" rows="4" />
        <flux:error name="description" />
    </flux:field>

    <flux:field>
        <flux:label>{{ __('Call To Action URL') }}</flux:label>
        <flux:input type="url" wire:model="callToAction" placeholder="https://example.com" />
        <flux:error name="callToAction" />
    </flux:field>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:field>
            <flux:label>{{ __('Start Date') }}</flux:label>
            <flux:input type="date" wire:model="startDate" />
            <flux:error name="startDate" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('End Date') }}</flux:label>
            <flux:input type="date" wire:model="endDate" />
            <flux:error name="endDate" />
        </flux:field>
    </div>

    <flux:field variant="inline">
        <flux:checkbox wire:model="isActive" />
        <flux:label>{{ __('Campaign active') }}</flux:label>
    </flux:field>

    <div class="flex justify-end gap-2 pt-2">
        <flux:button type="button" variant="filled" wire:click="$dispatch('close-sponsor-modal')">
            {{ __('Cancel') }}
        </flux:button>

        <flux:button type="submit" variant="primary">
            {{ __('Save Sponsor') }}
        </flux:button>
    </div>
</form>
