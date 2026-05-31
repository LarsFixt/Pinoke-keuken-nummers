<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sponsors;

use App\Models\Sponsor;
use App\Services\AdImageProcessor;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class AssetManager extends Component
{
    use WithFileUploads;

    public Sponsor $sponsor;

    public ?TemporaryUploadedFile $file = null;

    public string $targetScreen = 'kitchen';

    public int $durationSeconds = 10;

    public int $frequencyWeight = 1;

    /**
     * @var array<int, array{target_screen: string, duration_seconds: int, frequency_weight: int}>
     */
    public array $assetSettings = [];

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'file' => ['required', 'image:allow_svg', 'max:12288'],
            'targetScreen' => ['required', 'in:both,wedstrijdschema,kitchen'],
            'durationSeconds' => ['required', 'integer', 'between:1,120'],
            'frequencyWeight' => ['required', 'integer', 'between:1,5'],
        ];
    }

    #[Computed]
    public function assets()
    {
        return $this->sponsor->adAssets()->latest()->get();
    }

    public function save(AdImageProcessor $processor): void
    {
        $validated = $this->validate();

        $processed = $processor->processAndStore($validated['file']);

        if ($processed['is_vertical'] && in_array($validated['targetScreen'], ['wedstrijdschema', 'both'], true)) {
            Storage::disk('public')->delete($processed['file_path']);

            throw ValidationException::withMessages([
                'file' => __('Vertical images can only be displayed on the Kitchen screen.'),
            ]);
        }

        $this->sponsor->adAssets()->create([
            'file_path' => $processed['file_path'],
            'target_screen' => $validated['targetScreen'],
            'size_format' => 'kitchen_1x1',
            'is_vertical' => $processed['is_vertical'],
            'duration_seconds' => $validated['durationSeconds'],
            'frequency_weight' => $validated['frequencyWeight'],
        ]);

        $this->reset('file', 'targetScreen', 'durationSeconds', 'frequencyWeight');
        $this->targetScreen = 'kitchen';
        $this->durationSeconds = 10;
        $this->frequencyWeight = 1;

        Flux::toast(__('Asset uploaded.'), variant: 'success');

        unset($this->assets);
        $this->dispatch('ad-asset-updated');
    }

    public function deleteAsset(int $assetId): void
    {
        $asset = $this->sponsor->adAssets()->findOrFail($assetId);

        Storage::disk('public')->delete($asset->file_path);
        $asset->delete();

        unset($this->assetSettings[$assetId]);

        Flux::toast(__('Asset deleted.'), variant: 'success');

        unset($this->assets);
        $this->dispatch('ad-asset-updated');
    }

    public function updateAssetSettings(int $assetId): void
    {
        $asset = $this->sponsor->adAssets()->findOrFail($assetId);

        $settings = Validator::make(
            ['settings' => $this->assetSettings[$assetId] ?? []],
            [
                'settings.target_screen' => ['required', 'in:both,wedstrijdschema,kitchen'],
                'settings.duration_seconds' => ['required', 'integer', 'between:1,120'],
                'settings.frequency_weight' => ['required', 'integer', 'between:1,5'],
            ]
        )->validate()['settings'];

        if ($asset->is_vertical && in_array($settings['target_screen'], ['wedstrijdschema', 'both'], true)) {
            throw ValidationException::withMessages([
                "assetSettings.$assetId.target_screen" => __('Vertical images can only be displayed on the Kitchen screen.'),
            ]);
        }

        $asset->update([
            'target_screen' => $settings['target_screen'],
            'duration_seconds' => (int) $settings['duration_seconds'],
            'frequency_weight' => (int) $settings['frequency_weight'],
        ]);

        Flux::toast(__('Asset settings updated.'), variant: 'success');

        unset($this->assets);
        $this->dispatch('ad-asset-updated');
    }

    public function prepareAssetSettings(
        int $assetId,
        string $targetScreen,
        int $durationSeconds,
        int $frequencyWeight
    ): void {
        if (array_key_exists($assetId, $this->assetSettings)) {
            return;
        }

        $this->assetSettings[$assetId] = [
            'target_screen' => $targetScreen,
            'duration_seconds' => $durationSeconds,
            'frequency_weight' => $frequencyWeight,
        ];
    }

    public function render()
    {
        return view('livewire.admin.sponsors.asset-manager');
    }
}
