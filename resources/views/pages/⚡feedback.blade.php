<?php

use App\Models\Feedback;
use App\NotificationTiming;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Feedback')] class extends Component {
    use WithPagination;

    /**
     * Which responses to show: today, week or all.
     */
    #[Url]
    public string $period = 'week';

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    /**
     * @return array{responses: int, averageRating: float|null, onTimePercentage: int|null, lateOrMissedPercentage: int|null}
     */
    #[Computed]
    public function summary(): array
    {
        $responses = $this->query()->count();
        $timingAnswers = $this->query()->whereNotNull('notification_timing')->count();
        $onTime = $this->query()->where('notification_timing', NotificationTiming::OnTime)->count();
        $averageRating = $this->query()->avg('rating');

        return [
            'responses' => $responses,
            'averageRating' => $averageRating === null ? null : round((float) $averageRating, 1),
            'onTimePercentage' => $timingAnswers ? (int) round($onTime / $timingAnswers * 100) : null,
            'lateOrMissedPercentage' => $timingAnswers ? 100 - (int) round($onTime / $timingAnswers * 100) : null,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Feedback>
     */
    #[Computed]
    public function responses(): LengthAwarePaginator
    {
        return $this->query()->with('order')->latest()->latest('id')->paginate(20);
    }

    /**
     * @return Builder<Feedback>
     */
    private function query(): Builder
    {
        return Feedback::query()->when(
            $this->period !== 'all',
            fn (Builder $query) => $query->where('created_at', '>=', $this->period === 'today' ? now()->startOfDay() : now()->subDays(7)),
        );
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="relative w-full">
        <flux:heading size="xl" level="1">{{ __('Feedback') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('What guests say once their order is ready') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <flux:radio.group wire:model.live="period" variant="segmented" class="max-w-sm">
        <flux:radio value="today" :label="__('Today')" />
        <flux:radio value="week" :label="__('Last 7 days')" />
        <flux:radio value="all" :label="__('All')" />
    </flux:radio.group>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <flux:card class="p-4!">
            <flux:text>{{ __('Responses') }}</flux:text>
            <flux:heading size="xl">{{ $this->summary['responses'] }}</flux:heading>
        </flux:card>
        <flux:card class="p-4!">
            <flux:text>{{ __('Average rating') }}</flux:text>
            <flux:heading size="xl">{{ $this->summary['averageRating'] ?? '–' }}<span class="text-sm font-normal"> / 5</span></flux:heading>
        </flux:card>
        <flux:card class="p-4!">
            <flux:text>{{ __('Notified in time') }}</flux:text>
            <flux:heading size="xl">{{ $this->summary['onTimePercentage'] === null ? '–' : $this->summary['onTimePercentage'] . '%' }}</flux:heading>
        </flux:card>
        <flux:card class="p-4!">
            <flux:text>{{ __('Late or missed') }}</flux:text>
            <flux:heading size="xl">{{ $this->summary['lateOrMissedPercentage'] === null ? '–' : $this->summary['lateOrMissedPercentage'] . '%' }}</flux:heading>
        </flux:card>
    </div>

    <div class="flex flex-col gap-3">
        @forelse ($this->responses as $response)
            <flux:card wire:key="feedback-{{ $response->id }}" class="flex flex-col gap-2 p-4!">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="lg">#{{ $response->order?->number ?? '–' }}</flux:heading>
                    @if ($response->rating)
                        <span class="text-2xl" title="{{ __(':score out of 5', ['score' => $response->rating]) }}">{{ [1 => '😞', 2 => '🙁', 3 => '😐', 4 => '🙂', 5 => '😄'][$response->rating] }}</span>
                    @endif
                    @if ($response->notification_timing)
                        <flux:badge size="sm" :color="$response->notification_timing->color()">{{ $response->notification_timing->label() }}</flux:badge>
                    @endif
                    <flux:spacer />
                    <flux:text size="sm">{{ $response->created_at->format('d-m H:i') }}</flux:text>
                </div>

                @if ($response->comment)
                    <flux:text class="text-base text-zinc-800 dark:text-zinc-100">“{{ $response->comment }}”</flux:text>
                @endif

                <flux:text size="sm">
                    {{ collect([
                        $response->platform,
                        $response->display_mode === 'standalone' ? __('Home screen app') : null,
                        $response->notification_mode,
                        $response->entry ? __('via :entry', ['entry' => $response->entry]) : null,
                        $response->wait_minutes !== null ? __(':minutes min wait', ['minutes' => $response->wait_minutes]) : null,
                    ])->filter()->implode(' · ') }}
                </flux:text>
            </flux:card>
        @empty
            <flux:text>{{ __('No feedback yet.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->responses->links() }}
</div>
