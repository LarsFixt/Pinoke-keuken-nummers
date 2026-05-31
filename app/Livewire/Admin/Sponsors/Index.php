<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sponsors;

use App\Models\Sponsor;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Sponsors')]
class Index extends Component
{
    public bool $showSponsorModal = false;

    public bool $showAssetsModal = false;

    public ?int $selectedSponsorId = null;

    public int $formRenderKey = 0;

    public int $assetManagerRenderKey = 0;

    public int $concurrentSponsors = 1;

    public function mount(): void
    {
        $this->concurrentSponsors = $this->resolveConcurrentSponsorsSetting(
            cache('display.concurrent_sponsors', 1)
        );
    }

    #[Computed]
    public function sponsors()
    {
        return Sponsor::query()
            ->withCount('adAssets')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedSponsor(): ?Sponsor
    {
        if ($this->selectedSponsorId === null) {
            return null;
        }

        return Sponsor::query()->find($this->selectedSponsorId);
    }

    public function createSponsor(): void
    {
        $this->selectedSponsorId = null;
        $this->formRenderKey++;
        $this->showSponsorModal = true;
    }

    public function editSponsor(int $sponsorId): void
    {
        $this->selectedSponsorId = $sponsorId;
        $this->formRenderKey++;
        $this->showSponsorModal = true;
    }

    public function manageAssets(int $sponsorId): void
    {
        $this->selectedSponsorId = $sponsorId;
        $this->assetManagerRenderKey++;
        $this->showAssetsModal = true;
    }

    public function deleteSponsor(int $sponsorId): void
    {
        Sponsor::query()->findOrFail($sponsorId)->delete();

        unset($this->sponsors);
    }

    public function saveDisplaySettings(): void
    {
        $validated = $this->validate([
            'concurrentSponsors' => ['required', 'integer', 'between:1,6'],
        ]);

        $this->concurrentSponsors = $this->resolveConcurrentSponsorsSetting($validated['concurrentSponsors']);

        cache()->forever('display.concurrent_sponsors', $this->concurrentSponsors);

        Flux::toast(__('Display settings saved.'), variant: 'success');
    }

    #[On('close-sponsor-modal')]
    public function closeSponsorModal(): void
    {
        $this->showSponsorModal = false;
    }

    #[On('sponsor-saved')]
    public function onSponsorSaved(): void
    {
        $this->showSponsorModal = false;
        unset($this->sponsors);
    }

    #[On('ad-asset-updated')]
    public function onAdAssetUpdated(): void
    {
        unset($this->sponsors);
    }

    public function render()
    {
        return view('livewire.admin.sponsors.index');
    }

    private function resolveConcurrentSponsorsSetting(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        if ($integer === null) {
            return 1;
        }

        return max(1, min(6, $integer));
    }
}
