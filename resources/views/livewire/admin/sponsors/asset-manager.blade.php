<div class="space-y-6">
    <form wire:submit="save" class="space-y-4">
        <flux:field>
            <flux:label>{{ __('Image File') }}</flux:label>
            <flux:input type="file" wire:model="file" accept="image/*,.svg" />
            <flux:error name="file" />
        </flux:field>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:field>
                <flux:label>{{ __('Target Screen') }}</flux:label>
                <flux:select wire:model="targetScreen">
                    <flux:select.option value="kitchen">{{ __('Kitchen') }}</flux:select.option>
                    <flux:select.option value="wedstrijdschema">{{ __('Wedstrijdschema') }}</flux:select.option>
                    <flux:select.option value="both">{{ __('Both') }}</flux:select.option>
                </flux:select>
                <flux:error name="targetScreen" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Duration (seconds)') }}</flux:label>
                <flux:input type="number" min="1" max="120" wire:model="durationSeconds" />
                <flux:error name="durationSeconds" />
            </flux:field>
        </div>

        <flux:field>
            <flux:label>{{ __('Frequency Weight (1-5)') }}</flux:label>
            <flux:input type="number" min="1" max="5" wire:model="frequencyWeight" />
            <flux:error name="frequencyWeight" />
        </flux:field>

        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file,save">
            {{ __('Upload Asset') }}
        </flux:button>
    </form>

    <flux:separator variant="subtle" />

    <div class="grid gap-4 sm:grid-cols-2">
        @forelse ($this->assets as $asset)
            @php($this->prepareAssetSettings($asset->id, $asset->target_screen, $asset->duration_seconds, $asset->frequency_weight))

            <flux:card wire:key="asset-{{ $asset->id }}" class="space-y-3">
                <img src="{{ asset('storage/' . $asset->file_path) }}" alt="{{ $sponsor->name }}"
                    class="h-36 w-full rounded-lg bg-zinc-100 object-contain dark:bg-zinc-900" />

                <form wire:submit="updateAssetSettings({{ $asset->id }})" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Target Screen') }}</flux:label>
                            <flux:select wire:model="assetSettings.{{ $asset->id }}.target_screen">
                                <flux:select.option value="kitchen">{{ __('Kitchen') }}</flux:select.option>
                                <flux:select.option value="wedstrijdschema">{{ __('Wedstrijdschema') }}
                                </flux:select.option>
                                <flux:select.option value="both">{{ __('Both') }}</flux:select.option>
                            </flux:select>
                            <flux:error name="assetSettings.{{ $asset->id }}.target_screen" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Duration (seconds)') }}</flux:label>
                            <flux:input type="number" min="1" max="120"
                                wire:model="assetSettings.{{ $asset->id }}.duration_seconds" />
                            <flux:error name="assetSettings.{{ $asset->id }}.duration_seconds" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Frequency Weight (1-5)') }}</flux:label>
                        <flux:input type="number" min="1" max="5"
                            wire:model="assetSettings.{{ $asset->id }}.frequency_weight" />
                        <flux:error name="assetSettings.{{ $asset->id }}.frequency_weight" />
                    </flux:field>

                    <flux:text size="sm"><strong>{{ __('Vertical:') }}</strong>
                        {{ $asset->is_vertical ? __('Yes') : __('No') }}</flux:text>

                    <div class="flex items-center gap-2">
                        <flux:button type="submit" size="sm" variant="primary">
                            {{ __('Update Settings') }}
                        </flux:button>

                        <flux:button type="button" variant="danger" size="sm" icon="trash"
                            wire:click="deleteAsset({{ $asset->id }})"
                            wire:confirm="{{ __('Delete this asset?') }}">
                            {{ __('Delete') }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        @empty
            <flux:card class="sm:col-span-2">
                <flux:text>{{ __('No assets uploaded yet.') }}</flux:text>
            </flux:card>
        @endforelse
    </div>
</div>
