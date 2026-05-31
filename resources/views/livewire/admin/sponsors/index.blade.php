<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    <div>
        <flux:heading size="xl" level="1">{{ __('Sponsors') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">
            {{ __('Manage ad campaigns and creative assets for all screens.') }}
        </flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <div class="flex justify-end">
        <flux:button icon="plus" variant="primary" wire:click="createSponsor">
            {{ __('New Sponsor') }}
        </flux:button>
    </div>

    <flux:card class="space-y-4">
        <div>
            <flux:heading size="lg">{{ __('Display Settings') }}</flux:heading>
            <flux:subheading>
                {{ __('Control how many sponsor cards are shown at the same time on the order overview.') }}
            </flux:subheading>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:field class="sm:max-w-48">
                <flux:label>{{ __('Concurrent sponsors') }}</flux:label>
                <flux:input type="number" min="1" max="6" wire:model="concurrentSponsors" />
                <flux:error name="concurrentSponsors" />
            </flux:field>

            <flux:button variant="primary" wire:click="saveDisplaySettings">
                {{ __('Save display settings') }}
            </flux:button>
        </div>
    </flux:card>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Period') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Active') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Assets') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->sponsors as $sponsor)
                <flux:table.row :key="$sponsor->id">
                    <flux:table.cell variant="strong">{{ $sponsor->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $sponsor->start_date->format('Y-m-d') }} - {{ $sponsor->end_date->format('Y-m-d') }}
                    </flux:table.cell>
                    <flux:table.cell align="center">
                        @if ($sponsor->is_active)
                            <flux:badge color="green">{{ __('Yes') }}</flux:badge>
                        @else
                            <flux:badge color="zinc">{{ __('No') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="center">{{ $sponsor->ad_assets_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" variant="filled" icon="photo"
                                wire:click="manageAssets({{ $sponsor->id }})">
                                {{ __('Assets') }}
                            </flux:button>

                            <flux:button size="sm" variant="subtle" icon="pencil-square"
                                wire:click="editSponsor({{ $sponsor->id }})">
                                {{ __('Edit') }}
                            </flux:button>

                            <flux:button size="sm" variant="danger" icon="trash"
                                wire:click="deleteSponsor({{ $sponsor->id }})"
                                wire:confirm="{{ __('Delete this sponsor and all its assets?') }}">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center">
                        {{ __('No sponsors yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal wire:model.self="showSponsorModal" flyout position="right" class="md:w-136">
        <div class="space-y-4">
            <flux:heading size="lg">
                {{ $selectedSponsorId ? __('Edit Sponsor') : __('Create Sponsor') }}
            </flux:heading>

            <livewire:admin.sponsors.form :sponsor-id="$selectedSponsorId" :key="'sponsor-form-' . $formRenderKey" />
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showAssetsModal" flyout position="right" class="md:w-2xl">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Manage Assets') }}</flux:heading>

            @if ($this->selectedSponsor)
                <flux:subheading>
                    {{ $this->selectedSponsor->name }}
                </flux:subheading>

                <livewire:admin.sponsors.asset-manager :sponsor="$this->selectedSponsor" :key="'sponsor-assets-' . $assetManagerRenderKey" />
            @endif
        </div>
    </flux:modal>
</div>
