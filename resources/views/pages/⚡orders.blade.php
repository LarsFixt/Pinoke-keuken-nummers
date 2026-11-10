<?php

use App\Console\Commands\DemoDisplayOrders;
use App\KitchenDevice;
use App\Models\KitchenTicket;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAlias;
use App\ProductCategory;
use App\Services\ProductMatcher;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Orders')] class extends Component {
    use WithPagination;

    /**
     * Above this many days the "over time" chart shows weeks instead of days.
     */
    private const int DAYS_SHOWN_PER_DAY = 62;

    /**
     * Which tickets to count: week, weekend, month, season or all.
     */
    #[Url]
    public string $period = 'month';

    /**
     * Only count tickets from the register with this name; empty for all registers.
     */
    #[Url]
    public string $register = '';

    #[Url]
    public bool $includeStaffMeals = false;

    /**
     * Which part of the page to show: overview or quality.
     */
    #[Url]
    public string $tab = 'overview';

    /**
     * The name read from tickets that is being linked to a product.
     */
    public ?string $nameToLink = null;

    /**
     * The product to link the name to, or "new" to add the name as a product.
     */
    public string $linkProductId = '';

    public string $newProductCategory = 'other';

    public string $newProductDevice = 'none';

    public bool $showLinkModal = false;

    /**
     * The period being counted, in the display timezone. "from" is null for all tickets.
     *
     * @return array{from: CarbonImmutable|null, to: CarbonImmutable}
     */
    #[Computed]
    public function range(): array
    {
        $today = CarbonImmutable::now(config('app.display_timezone'))->startOfDay();

        $from = match ($this->period) {
            'week' => $today->subDays(6),
            // Friday to Sunday of the current weekend, or of the last one on weekdays.
            'weekend' => $today->subDays(($today->dayOfWeekIso + 2) % 7),
            // The hockey season starts in August.
            'season' => $today->setDate($today->month >= 8 ? $today->year : $today->year - 1, 8, 1),
            'all' => null,
            default => $today->subDays(29),
        };

        return ['from' => $from, 'to' => $this->period === 'weekend' ? $from->addDays(3) : $today->addDay()];
    }

    /**
     * @return Collection<int, array{ticket: int, product: string, category: ProductCategory, device: KitchenDevice, quantity: int, uncertain: bool, known: bool, at: CarbonImmutable}>
     */
    #[Computed]
    public function lines(): Collection
    {
        return $this->linesBetween($this->range['from'], $this->range['to']);
    }

    /**
     * The lines of the period just before the selected one (the weekend before for a weekend), to compare with.
     *
     * @return Collection<int, array{ticket: int, product: string, category: ProductCategory, device: KitchenDevice, quantity: int, uncertain: bool, known: bool, at: CarbonImmutable}>|null
     */
    #[Computed]
    public function previousLines(): ?Collection
    {
        $from = $this->range['from'];
        $shiftDays = $this->period === 'weekend' ? 7 : (int) round($from?->diffInDays($this->range['to']) ?? 0);
        $previousFrom = $from?->subDays($shiftDays);
        $firstTicketAt = KitchenTicket::min(DB::raw('coalesce(captured_at, created_at)'));

        // Comparing with a period from before the first ticket would only show a meaningless jump.
        if ($previousFrom === null || $firstTicketAt === null || CarbonImmutable::parse($firstTicketAt, config('app.timezone'))->isAfter($previousFrom)) {
            return null;
        }

        return $this->linesBetween($previousFrom, $this->range['to']->subDays($shiftDays));
    }

    /**
     * @return array{items: int, tickets: int, itemsPerTicket: float|null, busiestMoment: string|null, itemsChange: int|null, ticketsChange: int|null}
     */
    #[Computed]
    public function summary(): array
    {
        $tickets = $this->lines->unique('ticket')->count();
        $items = (int) $this->lines->sum('quantity');
        $busiestMoment = $this->lines
            ->groupBy(fn (array $line): string => $line['at']->isoWeekday().'-'.$line['at']->hour)
            ->map->sum('quantity')->sortDesc()->keys()->first();

        return [
            'items' => $items,
            'tickets' => $tickets,
            'itemsPerTicket' => $tickets ? round($items / $tickets, 1) : null,
            'busiestMoment' => $busiestMoment === null ? null : (function (string $moment): string {
                [$weekday, $hour] = explode('-', $moment);

                return ucfirst($this->weekdayName((int) $weekday, 'dddd')).' '.sprintf('%02d:00', $hour);
            })($busiestMoment),
            'itemsChange' => $this->change($items, $this->previousLines?->sum('quantity')),
            'ticketsChange' => $this->change($tickets, $this->previousLines?->unique('ticket')->count()),
        ];
    }

    /**
     * The categories that have items in the period, in a fixed order so their colours stay the same.
     *
     * @return list<ProductCategory>
     */
    #[Computed]
    public function categories(): array
    {
        return array_values(array_filter(
            ProductCategory::cases(),
            fn (ProductCategory $category): bool => $this->lines->contains(fn (array $line): bool => $line['category'] === $category),
        ));
    }

    /**
     * Items per day (or per week for long periods), split by category.
     *
     * @return list<array<string, int|string>>
     */
    #[Computed]
    public function itemsOverTime(): array
    {
        $buckets = $this->timeBuckets();
        $totals = $this->lines
            ->groupBy(fn (array $line): string => $buckets['keyFor']($line['at']))
            ->map(fn (Collection $lines): array => $lines->groupBy(fn (array $line): string => $line['category']->value)->map->sum('quantity')->all());

        return array_map(function (string $date) use ($totals): array {
            $row = ['date' => $date];

            foreach ($this->categories as $category) {
                $row[$category->value] = $totals[$date][$category->value] ?? 0;
            }

            return $row;
        }, $buckets['keys']);
    }

    /**
     * Items per hour of the day, split by category.
     *
     * @return list<array<string, int|string>>
     */
    #[Computed]
    public function itemsByHour(): array
    {
        if ($this->lines->isEmpty()) {
            return [];
        }

        $totals = $this->lines
            ->groupBy(fn (array $line): int => $line['at']->hour)
            ->map(fn (Collection $lines): array => $lines->groupBy(fn (array $line): string => $line['category']->value)->map->sum('quantity')->all());

        return array_map(function (int $hour) use ($totals): array {
            $row = ['hour' => __(':hour h', ['hour' => $hour])];

            foreach ($this->categories as $category) {
                $row[$category->value] = $totals[$hour][$category->value] ?? 0;
            }

            return $row;
        }, range($totals->keys()->min(), $totals->keys()->max()));
    }

    /**
     * Items per weekday and hour, for the heatmap.
     *
     * @return array{hours: list<int>, days: array<int, array{name: string, hours: array<int, int>}>, max: int}
     */
    #[Computed]
    public function heatmap(): array
    {
        $totals = $this->lines
            ->groupBy(fn (array $line): string => $line['at']->isoWeekday().'-'.$line['at']->hour)
            ->map->sum('quantity');
        $hours = $this->lines->isEmpty() ? [] : range($this->lines->min(fn (array $line): int => $line['at']->hour), $this->lines->max(fn (array $line): int => $line['at']->hour));
        $days = [];

        foreach (range(1, 7) as $weekday) {
            $days[$weekday] = [
                'name' => $this->weekdayName($weekday, 'dd'),
                'hours' => array_combine($hours, array_map(fn (int $hour): int => (int) ($totals["{$weekday}-{$hour}"] ?? 0), $hours)),
            ];
        }

        return ['hours' => $hours, 'days' => $days, 'max' => (int) ($totals->max() ?? 0)];
    }

    /**
     * Times ordered per product, most ordered first.
     *
     * @return Collection<int, array{name: string, category: ProductCategory, known: bool, items: int, tickets: int, uncertain: int, share: float, change: int|null, trend: list<int>}>
     */
    #[Computed]
    public function products(): Collection
    {
        $totalItems = max(1, $this->summary['items']);
        $previous = $this->previousLines?->groupBy('product')->map->sum('quantity');
        $buckets = $this->timeBuckets();

        return $this->lines->groupBy('product')->map(function (Collection $lines, string $name) use ($totalItems, $previous, $buckets): array {
            $items = (int) $lines->sum('quantity');
            $perBucket = $lines->groupBy(fn (array $line): string => $buckets['keyFor']($line['at']))->map->sum('quantity');

            return [
                'name' => $name,
                'category' => $lines->first()['category'],
                'known' => $lines->first()['known'],
                'items' => $items,
                'tickets' => $lines->unique('ticket')->count(),
                'uncertain' => $lines->where('uncertain', true)->count(),
                'share' => round($items / $totalItems * 100, 1),
                'change' => $previous === null ? null : $this->change($items, $previous[$name] ?? 0),
                'trend' => array_map(fn (string $key): int => (int) ($perBucket[$key] ?? 0), $buckets['keys']),
            ];
        })->sortByDesc('items')->values();
    }

    /**
     * When each kitchen device is needed, per weekday: the usual first and last order and the items per hour.
     *
     * "Usual" is the median over the days in the period. The device should be on its warm-up time before the first order.
     *
     * @return array{hours: list<int>, devices: list<array{device: KitchenDevice, items: int, weekdays: list<array{name: string, days: int, switchOnAt: string, firstOrderAt: string, lastOrderAt: string, itemsPerHour: array<int, float>}>}>, max: float}
     */
    #[Computed]
    public function deviceUsage(): array
    {
        $lines = $this->lines->reject(fn (array $line): bool => $line['device'] === KitchenDevice::None);

        if ($lines->isEmpty()) {
            return ['hours' => [], 'devices' => [], 'max' => 0.0];
        }

        $hours = range($lines->min(fn (array $line): int => $line['at']->hour), $lines->max(fn (array $line): int => $line['at']->hour));
        $minuteOfDay = fn (CarbonImmutable $at): int => $at->hour * 60 + $at->minute;
        $devices = [];
        $max = 0.0;

        foreach (KitchenDevice::cases() as $device) {
            $deviceLines = $lines->filter(fn (array $line): bool => $line['device'] === $device);

            if ($deviceLines->isEmpty()) {
                continue;
            }

            $weekdays = [];

            foreach ($deviceLines->groupBy(fn (array $line): int => $line['at']->isoWeekday())->sortKeys() as $weekday => $weekdayLines) {
                $perDate = $weekdayLines->groupBy(fn (array $line): string => $line['at']->toDateString());
                $firstOrder = (int) $perDate->map(fn (Collection $day): int => $minuteOfDay($day->min('at')))->median();
                $lastOrder = (int) $perDate->map(fn (Collection $day): int => $minuteOfDay($day->max('at')))->median();
                $perHour = $weekdayLines->groupBy(fn (array $line): int => $line['at']->hour)->map->sum('quantity');
                $itemsPerHour = array_combine($hours, array_map(fn (int $hour): float => round(($perHour[$hour] ?? 0) / $perDate->count(), 1), $hours));
                $max = max($max, ...array_values($itemsPerHour));

                $weekdays[] = [
                    'name' => ucfirst($this->weekdayName($weekday, 'dddd')),
                    'days' => $perDate->count(),
                    'switchOnAt' => $this->timeOfDay(intdiv(max(0, $firstOrder - $device->warmUpMinutes()), 15) * 15),
                    'firstOrderAt' => $this->timeOfDay($firstOrder),
                    'lastOrderAt' => $this->timeOfDay($lastOrder),
                    'itemsPerHour' => $itemsPerHour,
                ];
            }

            $devices[] = ['device' => $device, 'items' => (int) $deviceLines->sum('quantity'), 'weekdays' => $weekdays];
        }

        return ['hours' => $hours, 'devices' => $devices, 'max' => $max];
    }

    /**
     * @return Collection<int, string>
     */
    #[Computed]
    public function registers(): Collection
    {
        return KitchenTicket::whereNotNull('register_name')->distinct()->orderBy('register_name')->pluck('register_name');
    }

    /**
     * Names read from tickets that are not linked to a product yet.
     *
     * @return Collection<int, object{name: string, lines: int, last_seen: string}>
     */
    #[Computed]
    public function unknownNames(): Collection
    {
        return OrderItem::whereNull('product_id')
            ->selectRaw('name, count(*) as `lines`, max(created_at) as last_seen')
            ->groupBy('name')
            ->orderByDesc('lines')
            ->toBase()
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function catalogue(): Collection
    {
        return Product::with('aliases')->orderBy('category')->orderBy('name')->get();
    }

    /**
     * Tickets with a warning or a quantity that was not read clearly.
     *
     * @return LengthAwarePaginator<int, KitchenTicket>
     */
    #[Computed]
    public function ticketsToCheck(): LengthAwarePaginator
    {
        return KitchenTicket::query()
            ->where('external_id', 'not like', DemoDisplayOrders::EXTERNAL_ID_PREFIX.'%')
            ->where(fn (Builder $query) => $query
                ->whereJsonLength('warnings', '>', 0)
                ->orWhereHas('orderItems', fn (Builder $query) => $query->where('is_quantity_uncertain', true)))
            ->with('orderItems')
            ->latest('captured_at')
            ->latest('id')
            ->paginate(10);
    }

    public function startLinking(string $name): void
    {
        $this->resetValidation();
        $this->nameToLink = $name;
        $this->linkProductId = '';
        $this->newProductCategory = ProductCategory::Other->value;
        $this->newProductDevice = KitchenDevice::None->value;
        $this->showLinkModal = true;
    }

    /**
     * Link the name to a product as another spelling, or add it as a new product.
     */
    public function linkName(ProductMatcher $productMatcher): void
    {
        $isNewProduct = $this->linkProductId === 'new';

        $this->validate([
            'nameToLink' => ['required', 'string', 'max:100', ...($isNewProduct ? [Rule::unique('products', 'name')] : [])],
            'linkProductId' => ['required', $isNewProduct ? 'in:new' : Rule::exists('products', 'id')],
            'newProductCategory' => [Rule::enum(ProductCategory::class)],
            'newProductDevice' => [Rule::enum(KitchenDevice::class)],
        ]);

        if ($isNewProduct) {
            Product::create(['name' => trim($this->nameToLink), 'category' => $this->newProductCategory, 'device' => $this->newProductDevice]);
        } else {
            ProductAlias::updateOrCreate(['alias' => $this->nameToLink], ['product_id' => (int) $this->linkProductId]);
        }

        $linked = $productMatcher->linkUnmatchedItems();

        $this->showLinkModal = false;
        $this->nameToLink = null;
        unset($this->unknownNames, $this->catalogue);

        Flux::toast(trans_choice(':count order line linked.|:count order lines linked.', $linked), variant: 'success');
    }

    public function updateCategory(int $productId, string $category): void
    {
        $category = ProductCategory::tryFrom($category);
        abort_if($category === null, 422);

        Product::findOrFail($productId)->update(['category' => $category]);
        unset($this->catalogue);

        Flux::toast(__('Category saved.'), variant: 'success');
    }

    public function updateDevice(int $productId, string $device): void
    {
        $device = KitchenDevice::tryFrom($device);
        abort_if($device === null, 422);

        Product::findOrFail($productId)->update(['device' => $device]);
        unset($this->catalogue);

        Flux::toast(__('Device saved.'), variant: 'success');
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    /**
     * @return Collection<int, array{ticket: int, product: string, category: ProductCategory, device: KitchenDevice, quantity: int, uncertain: bool, known: bool, at: CarbonImmutable}>
     */
    private function linesBetween(?CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $orderedAt = 'coalesce(kitchen_tickets.captured_at, kitchen_tickets.created_at)';
        $storedTime = fn (CarbonImmutable $time): string => $time->setTimezone(config('app.timezone'))->toDateTimeString();

        return OrderItem::query()
            ->join('kitchen_tickets', 'kitchen_tickets.id', '=', 'order_items.kitchen_ticket_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('kitchen_tickets.external_id', 'not like', DemoDisplayOrders::EXTERNAL_ID_PREFIX.'%')
            ->when($from, fn (Builder $query) => $query->whereRaw("{$orderedAt} >= ?", [$storedTime($from)]))
            ->whereRaw("{$orderedAt} < ?", [$storedTime($to)])
            ->when($this->register !== '', fn (Builder $query) => $query->where('kitchen_tickets.register_name', $this->register))
            ->when(! $this->includeStaffMeals, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('products.category')
                ->orWhere('products.category', '!=', ProductCategory::Staff->value)))
            ->toBase()
            ->get([
                'order_items.kitchen_ticket_id',
                'order_items.name',
                'order_items.quantity',
                'order_items.is_quantity_uncertain',
                'products.name as product_name',
                'products.category',
                'products.device',
                DB::raw("{$orderedAt} as ordered_at"),
            ])
            ->map(fn (object $row): array => [
                'ticket' => (int) $row->kitchen_ticket_id,
                'product' => $row->product_name ?? $row->name,
                'category' => ProductCategory::tryFrom((string) $row->category) ?? ProductCategory::Other,
                'device' => KitchenDevice::tryFrom((string) $row->device) ?? KitchenDevice::None,
                'quantity' => (int) $row->quantity,
                'uncertain' => (bool) $row->is_quantity_uncertain,
                'known' => $row->product_name !== null,
                'at' => CarbonImmutable::parse($row->ordered_at, config('app.timezone'))->setTimezone(config('app.display_timezone')),
            ]);
    }

    /**
     * The days (or weeks) of the period, and how to find the one a time falls in.
     *
     * @return array{keys: list<string>, keyFor: Closure(CarbonImmutable): string}
     */
    private function timeBuckets(): array
    {
        $from = $this->range['from'] ?? $this->lines->min('at')?->startOfDay();
        $to = $this->range['to'];

        if ($from === null) {
            return ['keys' => [], 'keyFor' => fn (CarbonImmutable $at): string => ''];
        }

        $perWeek = $from->diffInDays($to) > self::DAYS_SHOWN_PER_DAY;
        $keyFor = fn (CarbonImmutable $at): string => ($perWeek ? $at->startOfWeek() : $at->startOfDay())->format('Y-m-d');
        $keys = [];

        for ($day = $perWeek ? $from->startOfWeek() : $from; $day < $to; $day = $perWeek ? $day->addWeek() : $day->addDay()) {
            $keys[] = $day->format('Y-m-d');
        }

        return ['keys' => $keys, 'keyFor' => $keyFor];
    }

    /**
     * The change from the previous period in percent, or null when there is nothing to compare with.
     */
    private function change(int $now, ?int $before): ?int
    {
        return $before ? (int) round(($now - $before) / $before * 100) : null;
    }

    private function timeOfDay(int $minuteOfDay): string
    {
        return sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }

    private function weekdayName(int $isoWeekday, string $format): string
    {
        return CarbonImmutable::now()->startOfWeek()->addDays($isoWeekday - 1)->locale(app()->getLocale())->isoFormat($format);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="relative w-full">
        <flux:heading size="xl" level="1">{{ __('Orders') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('What the kitchen made, read from the kitchen tickets') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <flux:radio.group wire:model.live="tab" variant="segmented" class="max-w-sm">
        <flux:radio value="overview" :label="__('Overview')" />
        <flux:radio value="quality" :label="__('Data quality')" />
    </flux:radio.group>

    @if ($tab === 'quality')
        <flux:card class="flex flex-col gap-2">
            <flux:heading size="lg">{{ __('Unrecognised names') }}</flux:heading>
            <flux:text>{{ __('Names read from tickets that do not match a product. Link a misreading to its product, or add a new product.') }}</flux:text>

            @if ($this->unknownNames->isEmpty())
                <flux:text class="py-4">{{ __('Every name is linked to a product.') }}</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name on the ticket') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Lines') }}</flux:table.column>
                        <flux:table.column class="hidden sm:table-cell">{{ __('Last seen') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->unknownNames as $unknown)
                            <flux:table.row wire:key="unknown-{{ md5($unknown->name) }}">
                                <flux:table.cell variant="strong">{{ $unknown->name }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $unknown->lines }}</flux:table.cell>
                                <flux:table.cell class="hidden sm:table-cell">
                                    {{ \Carbon\CarbonImmutable::parse($unknown->last_seen, config('app.timezone'))->timezone(config('app.display_timezone'))->format('d-m-Y H:i') }}
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="sm" icon="link" wire:click="startLinking(@js($unknown->name))">{{ __('Link') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-2">
            <flux:heading size="lg">{{ __('Tickets to check') }}</flux:heading>
            <flux:text>{{ __('Tickets with a warning from the ticket reader or a quantity that was not read clearly.') }}</flux:text>

            <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->ticketsToCheck as $ticket)
                    <div wire:key="check-ticket-{{ $ticket->id }}" class="flex flex-col gap-2 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading>#{{ $ticket->ticket_number ?? '–' }}</flux:heading>
                            <flux:text size="sm">{{ $ticket->register_name }}</flux:text>
                            @foreach ($ticket->warnings as $warning)
                                <flux:badge wire:key="warning-{{ $ticket->id }}-{{ $loop->index }}" size="sm" color="amber">{{ $warning }}</flux:badge>
                            @endforeach
                            <flux:spacer />
                            <flux:text size="sm" class="tabular-nums">{{ ($ticket->captured_at ?? $ticket->created_at)->timezone(config('app.display_timezone'))->format('d-m-Y H:i') }}</flux:text>
                        </div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach ($ticket->orderItems as $item)
                                <flux:text wire:key="check-item-{{ $item->id }}" size="sm" variant="strong">
                                    {{ $item->quantity }}@if ($item->is_quantity_uncertain)<span class="text-amber-500" title="{{ __('Quantity not read clearly') }}">?</span>@endif× {{ $item->name }}
                                </flux:text>
                            @endforeach
                        </div>
                        <flux:accordion>
                            <flux:accordion.item>
                                <flux:accordion.heading class="text-sm">{{ __('Text as read') }}</flux:accordion.heading>
                                <flux:accordion.content>
                                    <flux:text size="sm" class="overflow-x-auto whitespace-pre rounded-lg bg-zinc-100 p-3 font-mono dark:bg-zinc-800">{{ $ticket->raw_text }}</flux:text>
                                </flux:accordion.content>
                            </flux:accordion.item>
                        </flux:accordion>
                    </div>
                @empty
                    <flux:text class="py-4">{{ __('No tickets to check.') }}</flux:text>
                @endforelse
            </div>

            <flux:pagination :paginator="$this->ticketsToCheck" />
        </flux:card>

        <flux:card class="flex flex-col gap-2">
            <flux:heading size="lg">{{ __('Products') }}</flux:heading>
            <flux:text>{{ __('The category decides how a product is grouped in the charts, the device when it needs to be switched on.') }}</flux:text>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">{{ __('Also read as') }}</flux:table.column>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Device') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->catalogue as $product)
                        <flux:table.row wire:key="catalogue-{{ $product->id }}">
                            <flux:table.cell variant="strong">{{ $product->name }}</flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">{{ $product->aliases->pluck('alias')->implode(', ') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:select size="sm" class="max-w-48" wire:change="updateCategory({{ $product->id }}, $event.target.value)">
                                    @foreach (\App\ProductCategory::cases() as $category)
                                        <flux:select.option wire:key="category-{{ $product->id }}-{{ $category->value }}" :value="$category->value" :selected="$product->category === $category">{{ $category->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:select size="sm" class="max-w-40" wire:change="updateDevice({{ $product->id }}, $event.target.value)">
                                    @foreach (\App\KitchenDevice::cases() as $device)
                                        <flux:select.option wire:key="device-{{ $product->id }}-{{ $device->value }}" :value="$device->value" :selected="$product->device === $device">{{ $device->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:modal wire:model.self="showLinkModal" class="md:w-96">
            <form wire:submit="linkName" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Link “:name”', ['name' => $nameToLink]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Lines with this name will count for the chosen product, now and on future tickets.') }}</flux:text>
                </div>

                <flux:select wire:model.live="linkProductId" :label="__('Product')">
                    <flux:select.option value="">{{ __('Choose a product…') }}</flux:select.option>
                    <flux:select.option value="new">{{ __('+ Add as a new product') }}</flux:select.option>
                    @foreach ($this->catalogue->sortBy('name') as $product)
                        <flux:select.option wire:key="link-option-{{ $product->id }}" :value="$product->id">{{ $product->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($linkProductId === 'new')
                    <flux:select wire:model="newProductCategory" :label="__('Category')">
                        @foreach (\App\ProductCategory::cases() as $category)
                            <flux:select.option wire:key="new-category-{{ $category->value }}" :value="$category->value">{{ $category->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="newProductDevice" :label="__('Device')">
                        @foreach (\App\KitchenDevice::cases() as $device)
                            <flux:select.option wire:key="new-device-{{ $device->value }}" :value="$device->value">{{ $device->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:error name="nameToLink" />

                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Link') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @else
        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="period" class="sm:hidden">
                <flux:select.option value="weekend">{{ __('Last weekend') }}</flux:select.option>
                <flux:select.option value="week">{{ __('Last 7 days') }}</flux:select.option>
                <flux:select.option value="month">{{ __('Last 30 days') }}</flux:select.option>
                <flux:select.option value="season">{{ __('Season') }}</flux:select.option>
                <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            </flux:select>

            <flux:radio.group wire:model.live="period" variant="segmented" class="hidden sm:flex">
                <flux:radio value="weekend" :label="__('Last weekend')" />
                <flux:radio value="week" :label="__('7 days')" />
                <flux:radio value="month" :label="__('30 days')" />
                <flux:radio value="season" :label="__('Season')" />
                <flux:radio value="all" :label="__('All')" />
            </flux:radio.group>

            @if ($this->registers->count() > 1)
                <flux:select wire:model.live="register" class="max-w-52">
                    <flux:select.option value="">{{ __('All registers') }}</flux:select.option>
                    @foreach ($this->registers as $registerName)
                        <flux:select.option wire:key="register-{{ $registerName }}" :value="$registerName">{{ $registerName }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:switch wire:model.live="includeStaffMeals" :label="__('Include staff meals')" align="left" />
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['label' => __('Items ordered'), 'value' => $this->summary['items'], 'change' => $this->summary['itemsChange']],
                ['label' => __('Tickets'), 'value' => $this->summary['tickets'], 'change' => $this->summary['ticketsChange']],
                ['label' => __('Items per ticket'), 'value' => $this->summary['itemsPerTicket'] ?? '–', 'change' => null],
                ['label' => __('Busiest hour'), 'value' => $this->summary['busiestMoment'] ?? '–', 'change' => null],
            ] as $tile)
                <flux:card wire:key="tile-{{ $loop->index }}" class="p-4!">
                    <flux:text>{{ $tile['label'] }}</flux:text>
                    <div class="flex flex-wrap items-baseline gap-2">
                        <flux:heading size="xl" class="tabular-nums">{{ $tile['value'] }}</flux:heading>
                        @if ($tile['change'] !== null)
                            <flux:badge size="sm" :color="$tile['change'] >= 0 ? 'green' : 'red'" :title="__('Compared with the period before')">
                                {{ $tile['change'] >= 0 ? '+' : '' }}{{ $tile['change'] }}%
                            </flux:badge>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>

        @if ($this->lines->isEmpty())
            <flux:card>
                <flux:text>{{ __('No kitchen tickets in this period.') }}</flux:text>
            </flux:card>
        @else
            @php($chartKey = "{$period}-{$register}-" . (int) $includeStaffMeals)

            <flux:card class="flex min-w-0 flex-col gap-4">
                <flux:heading size="lg">{{ __('Items over time') }}</flux:heading>
                <flux:chart wire:key="over-time-{{ $chartKey }}" :value="$this->itemsOverTime">
                    <flux:chart.viewport class="aspect-[2/1] md:aspect-[4/1]">
                        <flux:chart.svg>
                            <flux:chart.stack width="80%">
                                @foreach ($this->categories as $category)
                                    <flux:chart.bar :field="$category->value" :class="$category->chartTextClass()" />
                                @endforeach
                            </flux:chart.stack>

                            <flux:chart.axis axis="x" field="date" :format="['day' => 'numeric', 'month' => 'short']">
                                <flux:chart.axis.tick />
                                <flux:chart.axis.line />
                            </flux:chart.axis>

                            <flux:chart.axis axis="y">
                                <flux:chart.axis.grid />
                                <flux:chart.axis.tick />
                            </flux:chart.axis>

                            <flux:chart.cursor type="area" />
                        </flux:chart.svg>
                    </flux:chart.viewport>

                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="date" :format="['weekday' => 'short', 'day' => 'numeric', 'month' => 'short']" />
                        @foreach (array_reverse($this->categories) as $category)
                            <flux:chart.tooltip.value :field="$category->value" :label="$category->label()" />
                        @endforeach
                    </flux:chart.tooltip>

                    <div class="flex flex-wrap justify-center gap-x-4 gap-y-2">
                        @foreach ($this->categories as $category)
                            <flux:chart.legend wire:key="legend-{{ $category->value }}" :label="$category->label()">
                                <flux:chart.legend.indicator :class="$category->chartBackgroundClass()" />
                            </flux:chart.legend>
                        @endforeach
                    </div>
                </flux:chart>
            </flux:card>

            <div class="grid gap-4 xl:grid-cols-2">
                <flux:card class="flex min-w-0 flex-col gap-4">
                    <flux:heading size="lg">{{ __('By hour of the day') }}</flux:heading>
                    <flux:chart wire:key="by-hour-{{ $chartKey }}" :value="$this->itemsByHour">
                        <flux:chart.viewport class="aspect-[2/1]">
                            <flux:chart.svg>
                                <flux:chart.stack width="80%">
                                    @foreach ($this->categories as $category)
                                        <flux:chart.bar :field="$category->value" :class="$category->chartTextClass()" />
                                    @endforeach
                                </flux:chart.stack>

                                <flux:chart.axis axis="x" field="hour">
                                    <flux:chart.axis.tick />
                                    <flux:chart.axis.line />
                                </flux:chart.axis>

                                <flux:chart.axis axis="y">
                                    <flux:chart.axis.grid />
                                    <flux:chart.axis.tick />
                                </flux:chart.axis>

                                <flux:chart.cursor type="area" />
                            </flux:chart.svg>
                        </flux:chart.viewport>

                        <flux:chart.tooltip>
                            <flux:chart.tooltip.heading field="hour" />
                            @foreach (array_reverse($this->categories) as $category)
                                <flux:chart.tooltip.value :field="$category->value" :label="$category->label()" />
                            @endforeach
                        </flux:chart.tooltip>
                    </flux:chart>
                </flux:card>

                <flux:card class="flex min-w-0 flex-col gap-4">
                    <flux:heading size="lg">{{ __('Busy moments in the week') }}</flux:heading>
                    <div class="overflow-x-auto">
                        <table class="w-full border-separate border-spacing-0.5 text-xs tabular-nums">
                            <thead>
                                <tr>
                                    <th></th>
                                    @foreach ($this->heatmap['hours'] as $hour)
                                        <th wire:key="heat-hour-{{ $hour }}" class="font-normal text-zinc-500 dark:text-zinc-400">{{ $hour }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->heatmap['days'] as $weekday => $day)
                                    <tr wire:key="heat-day-{{ $weekday }}">
                                        <th class="pe-1 text-start font-normal text-zinc-500 dark:text-zinc-400">{{ $day['name'] }}</th>
                                        @foreach ($day['hours'] as $hour => $items)
                                            <td wire:key="heat-{{ $weekday }}-{{ $hour }}" title="{{ $day['name'] }} {{ $hour }}:00 · {{ trans_choice(':count item|:count items', $items) }}"
                                                class="relative h-7 min-w-6 rounded-sm bg-zinc-100 text-center dark:bg-zinc-800">
                                                @if ($items > 0)
                                                    <span class="absolute inset-0 rounded-sm bg-amber-500" style="opacity: {{ round(0.15 + 0.85 * $items / max(1, $this->heatmap['max']), 2) }}"></span>
                                                    <span class="relative text-zinc-900 dark:text-white">{{ $items }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </flux:card>
            </div>

            @if ($this->deviceUsage['devices'] !== [])
                <flux:card class="flex min-w-0 flex-col gap-4">
                    <div>
                        <flux:heading size="lg">{{ __('When are the devices needed?') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Per weekday: the usual first and last order for each device (the median over the days in this period), and the average number of items per hour. Switch on is the first order minus the warm-up time.') }}</flux:text>
                    </div>

                    @foreach ($this->deviceUsage['devices'] as $usage)
                        <div wire:key="device-usage-{{ $usage['device']->value }}" class="flex flex-col gap-2">
                            <div class="flex flex-wrap items-baseline gap-2">
                                <flux:heading>{{ $usage['device']->label() }}</flux:heading>
                                <flux:text size="sm">{{ trans_choice(':count item|:count items', $usage['items']) }} · {{ __(':minutes min warm-up', ['minutes' => $usage['device']->warmUpMinutes()]) }}</flux:text>
                            </div>

                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>{{ __('Day') }}</flux:table.column>
                                    <flux:table.column align="end">{{ __('Switch on') }}</flux:table.column>
                                    <flux:table.column align="end" class="hidden sm:table-cell">{{ __('First order') }}</flux:table.column>
                                    <flux:table.column align="end">{{ __('Last order') }}</flux:table.column>
                                    <flux:table.column class="hidden md:table-cell">{{ __('Items per hour') }}</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($usage['weekdays'] as $weekday)
                                        <flux:table.row wire:key="device-usage-{{ $usage['device']->value }}-{{ $loop->index }}">
                                            <flux:table.cell variant="strong">
                                                {{ $weekday['name'] }}
                                                <flux:text size="sm" class="inline">({{ trans_choice(':count day|:count days', $weekday['days']) }})</flux:text>
                                            </flux:table.cell>
                                            <flux:table.cell align="end" class="tabular-nums">
                                                <flux:badge size="sm" color="amber">{{ $weekday['switchOnAt'] }}</flux:badge>
                                            </flux:table.cell>
                                            <flux:table.cell align="end" class="hidden tabular-nums sm:table-cell">{{ $weekday['firstOrderAt'] }}</flux:table.cell>
                                            <flux:table.cell align="end" class="tabular-nums">{{ $weekday['lastOrderAt'] }}</flux:table.cell>
                                            <flux:table.cell class="hidden w-full md:table-cell">
                                                <div class="grid min-w-72 gap-0.5" style="grid-template-columns: repeat({{ count($this->deviceUsage['hours']) }}, minmax(0, 1fr))">
                                                    @foreach ($weekday['itemsPerHour'] as $hour => $items)
                                                        <div wire:key="device-hour-{{ $usage['device']->value }}-{{ $loop->parent->index }}-{{ $hour }}"
                                                            title="{{ $hour }}:00 · {{ __(':items items per day', ['items' => $items]) }}"
                                                            class="relative flex h-6 items-center justify-center rounded-sm bg-zinc-100 text-[10px] tabular-nums dark:bg-zinc-800">
                                                            @if ($items > 0)
                                                                <span class="absolute inset-0 rounded-sm bg-amber-500" style="opacity: {{ round(0.15 + 0.85 * $items / max(0.1, $this->deviceUsage['max']), 2) }}"></span>
                                                                <span class="relative text-zinc-900 dark:text-white">{{ $items >= 10 ? round($items) : $items }}</span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @if ($loop->last)
                                                    <div class="mt-0.5 grid min-w-72 gap-0.5" style="grid-template-columns: repeat({{ count($this->deviceUsage['hours']) }}, minmax(0, 1fr))">
                                                        @foreach ($this->deviceUsage['hours'] as $hour)
                                                            <span wire:key="device-hour-label-{{ $usage['device']->value }}-{{ $hour }}" class="text-center text-[10px] text-zinc-500 dark:text-zinc-400">{{ $hour }}</span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    @endforeach
                </flux:card>
            @endif

            <flux:card class="flex flex-col gap-2">
                <flux:heading size="lg">{{ __('Products') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Product') }}</flux:table.column>
                        <flux:table.column class="hidden md:table-cell">{{ __('Category') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Times ordered') }}</flux:table.column>
                        <flux:table.column align="end" class="hidden sm:table-cell">{{ __('Tickets') }}</flux:table.column>
                        <flux:table.column class="hidden sm:table-cell">{{ __('Share') }}</flux:table.column>
                        <flux:table.column class="hidden xl:table-cell">{{ __('Trend') }}</flux:table.column>
                        <flux:table.column align="end" class="hidden md:table-cell">{{ __('vs. before') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->products as $product)
                            <flux:table.row wire:key="product-{{ md5($product['name']) }}">
                                <flux:table.cell variant="strong">
                                    {{ $product['name'] }}
                                    @unless ($product['known'])
                                        <flux:badge size="sm" color="amber" class="ms-1">{{ __('Unrecognised') }}</flux:badge>
                                    @endunless
                                </flux:table.cell>
                                <flux:table.cell class="hidden md:table-cell">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="size-2 rounded-full {{ $product['category']->chartBackgroundClass() }}"></span>
                                        {{ $product['category']->label() }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">
                                    {{ $product['items'] }}
                                    @if ($product['uncertain'])
                                        <flux:tooltip :content="trans_choice(':count line with a quantity that was not read clearly|:count lines with a quantity that was not read clearly', $product['uncertain'])">
                                            <span class="text-amber-500">*</span>
                                        </flux:tooltip>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end" class="hidden tabular-nums sm:table-cell">{{ $product['tickets'] }}</flux:table.cell>
                                <flux:table.cell class="hidden sm:table-cell">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                                            <div class="h-full {{ $product['category']->chartBackgroundClass() }}" style="width: {{ min(100, $product['share'] / max(1, $this->products->first()['share']) * 100) }}%"></div>
                                        </div>
                                        <span class="tabular-nums">{{ $product['share'] }}%</span>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="hidden xl:table-cell">
                                    <flux:chart wire:key="trend-{{ md5($product['name']) }}-{{ $chartKey }}" :value="$product['trend']" class="aspect-[4/1] w-28">
                                        <flux:chart.svg gutter="2">
                                            <flux:chart.line :class="$product['category']->chartTextClass()" curve="none" />
                                        </flux:chart.svg>
                                    </flux:chart>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="hidden tabular-nums md:table-cell">
                                    @if ($product['change'] === null)
                                        <span class="text-zinc-400">–</span>
                                    @else
                                        <span @class(['text-green-600 dark:text-green-400' => $product['change'] > 0, 'text-red-600 dark:text-red-400' => $product['change'] < 0])>
                                            {{ $product['change'] > 0 ? '+' : '' }}{{ $product['change'] }}%
                                        </span>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif
    @endif
</div>
