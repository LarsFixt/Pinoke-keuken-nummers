<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sponsors;

use App\Models\Sponsor;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Component;

class Form extends Component
{
    public ?int $sponsorId = null;

    public string $name = '';

    public string $title = '';

    public string $description = '';

    public string $callToAction = '';

    public string $startDate = '';

    public string $endDate = '';

    public bool $isActive = true;

    public function mount(?int $sponsorId = null): void
    {
        $this->sponsorId = $sponsorId;

        if ($sponsorId === null) {
            $today = CarbonImmutable::today();

            $this->startDate = $today->toDateString();
            $this->endDate = $today->addMonth()->toDateString();

            return;
        }

        $sponsor = Sponsor::query()->findOrFail($sponsorId);

        $this->name = $sponsor->name;
        $this->title = $sponsor->title ?? '';
        $this->description = $sponsor->description ?? '';
        $this->callToAction = $sponsor->call_to_action ?? '';
        $this->startDate = $sponsor->start_date->toDateString();
        $this->endDate = $sponsor->end_date->toDateString();
        $this->isActive = $sponsor->is_active;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'callToAction' => ['nullable', 'url:http,https', 'max:255'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'isActive' => ['required', 'boolean'],
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();

        Sponsor::query()->updateOrCreate(
            ['id' => $this->sponsorId],
            [
                'name' => $validated['name'],
                'title' => $this->normalizeNullableString($validated['title']),
                'description' => $this->normalizeNullableString($validated['description']),
                'call_to_action' => $this->normalizeNullableString($validated['callToAction']),
                'start_date' => $validated['startDate'],
                'end_date' => $validated['endDate'],
                'is_active' => $validated['isActive'],
            ]
        );

        Flux::toast(__('Sponsor saved.'), variant: 'success');

        $this->dispatch('sponsor-saved');
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function render()
    {
        return view('livewire.admin.sponsors.form');
    }
}
