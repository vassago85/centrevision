<?php

use App\Models\Visit;
use App\Support\Analytics\DataQualityAnalytics;
use App\Support\Analytics\DateRange;
use App\Support\Analytics\DayContextAnalytics;
use App\Support\Analytics\OccupancyAnalytics;
use App\Support\Analytics\SecurityAnalytics;
use App\Support\Analytics\TrafficAnalytics;
use App\Support\Reporting\ReportExporter;
use App\Support\Reporting\TrafficReport;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Reports')] class extends Component {
    #[Url(as: 'range', keep: true)]
    public string $rangeKey = '30d';

    #[Url(as: 'compare', keep: true)]
    public string $compareKey = 'previous';

    #[Url(as: 'audience', keep: true)]
    public string $audience = 'shopper';

    #[Url(as: 'section', keep: true)]
    public string $section = 'overview';

    #[Url(as: 'metric', keep: true)]
    public string $chartMetric = 'visits';

    #[Url(as: 'from', keep: true)]
    public ?string $fromDate = null;

    #[Url(as: 'to', keep: true)]
    public ?string $toDate = null;

    public function mount(): void
    {
        if (! array_key_exists($this->rangeKey, DateRange::reportOptions())) {
            $this->rangeKey = '30d';
        }

        if (! array_key_exists($this->compareKey, DateRange::comparisonOptions())) {
            $this->compareKey = 'previous';
        }

        if ($this->isShop() || ! array_key_exists($this->audience, DateRange::audienceOptions())) {
            $this->audience = 'shopper';
        }

        if ($this->rangeKey === 'custom') {
            $this->fromDate ??= now()->subDays(29)->toDateString();
            $this->toDate ??= now()->toDateString();
        }

        $this->normaliseSection();
        $this->normaliseMetric();
    }

    public function updatedRangeKey(): void
    {
        if ($this->rangeKey === 'custom') {
            $this->fromDate ??= now()->subDays(29)->toDateString();
            $this->toDate ??= now()->toDateString();
        }
    }

    public function updatedSection(): void
    {
        $this->normaliseSection();
    }

    public function updatedChartMetric(): void
    {
        $this->normaliseMetric();
    }

    #[Computed]
    public function range(): DateRange
    {
        if ($this->rangeKey === 'custom') {
            return DateRange::custom(
                $this->fromDate ?? now()->subDays(29)->toDateString(),
                $this->toDate ?? now()->toDateString(),
            );
        }

        return DateRange::make($this->rangeKey);
    }

    #[Computed]
    public function comparison(): ?DateRange
    {
        return $this->range()->comparisonRange($this->compareKey);
    }

    #[Computed]
    public function analytics(): TrafficAnalytics
    {
        return app(TrafficAnalytics::class)->forAudience($this->isShop() ? 'shopper' : $this->audience);
    }

    #[Computed]
    public function occupancy(): OccupancyAnalytics
    {
        return app(OccupancyAnalytics::class);
    }

    #[Computed]
    public function hasOccupancy(): bool
    {
        return $this->occupancy()->available();
    }

    #[Computed]
    public function canSeeOps(): bool
    {
        return auth()->user()->can('viewAny', Visit::class);
    }

    #[Computed]
    public function sections(): array
    {
        $items = [
            'overview' => 'Overview',
            'visits' => 'Visits',
            'dwell' => 'Dwell',
            'behaviour' => 'Visitor behaviour',
        ];

        if ($this->hasOccupancy) {
            $items['occupancy'] = 'Occupancy';
        }

        if ($this->canSeeOps) {
            $items['security'] = 'Security';
            $items['quality'] = 'Data quality';
        }

        return $items;
    }

    #[Computed]
    public function daily(): Collection
    {
        return $this->analytics()->visitsByDay($this->range());
    }

    /**
     * The Reports overview headline row. Kept deliberately short — five
     * cards that answer the "how did the centre trade?" question.
     * Everything else lives in {@see secondaryKpis()} so the row above
     * this one is not competing with itself.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function kpis(): array
    {
        $range = $this->range();
        $previous = $this->comparison();
        $a = $this->analytics();

        $visits = $a->totalVisits($range);
        $unique = $a->uniqueVehicles($range);
        $returnRate = $a->returningVehicleRate($range);
        $dwell = $a->dwellSummary($range);

        $daysInRange = max(1, $this->daily()->count());
        $dailyAverage = (int) round($visits / $daysInRange);
        $prevDailyAverage = $previous
            ? $a->totalVisits($previous) / max(1, $previous->days())
            : null;

        return [
            $this->kpi(
                'Visits',
                number_format($visits),
                $visits,
                $previous ? $a->totalVisits($previous) : null,
                null,
                'truck',
            ),
            $this->kpi(
                'Unique Visitors',
                number_format($unique),
                $unique,
                $previous ? $a->uniqueVehicles($previous) : null,
                null,
                'user-group',
            ),
            $this->kpi(
                'Return Rate',
                $returnRate === null ? '—' : $returnRate.'%',
                $returnRate,
                $previous ? $a->returningVehicleRate($previous) : null,
                null,
                'arrow-path',
            ),
            $this->kpi(
                'Average Dwell',
                $dwell['average'] === null ? '—' : $dwell['average'].' min',
                $dwell['average'],
                $previous ? $a->dwellSummary($previous)['average'] : null,
                null,
                'clock',
            ),
            $this->kpi(
                'Daily Average',
                number_format($dailyAverage),
                $dailyAverage,
                $prevDailyAverage,
                null,
                'chart-bar',
            ),
        ];
    }

    /**
     * Supporting metrics — busiest day, peak hour, median dwell, excluded
     * staff visits, returning-visitor count. All useful, none big enough
     * to deserve a full KPI card. Rendered as compact {@see \Illuminate\View\ComponentAttributeBag metric} rows so they
     * do not visually compete with Visits or Unique Visitors above.
     *
     * @return array<int, array<string, string|int|null>>
     */
    #[Computed]
    public function secondaryKpis(): array
    {
        $range = $this->range();
        $a = $this->analytics();
        $dwell = $a->dwellSummary($range);
        $peak = $a->peakHour($range);
        $busiest = $this->daily()->sortByDesc('count')->first();
        $excluded = $a->excludedVisitCount($range);
        $returning = $a->returningVehicles($range);

        return [
            [
                'label' => 'Busiest day',
                'value' => $busiest['label'] ?? '—',
                'detail' => $busiest ? number_format($busiest['count']).' visits' : null,
            ],
            [
                'label' => 'Peak hour',
                'value' => $peak['label'] ?? '—',
                'detail' => $peak === null ? null : number_format($peak['count']).' visits',
            ],
            [
                'label' => 'Median dwell',
                'value' => $dwell['median'] === null ? '—' : $dwell['median'].' min',
                'detail' => null,
            ],
            [
                'label' => 'Returning visitors',
                'value' => number_format($returning),
                'detail' => null,
            ],
            [
                'label' => 'Staff / regular excluded',
                'value' => number_format($excluded),
                'detail' => null,
            ],
        ];
    }

    #[Computed]
    public function trend(): array
    {
        $metric = $this->chartMetric === 'occupancy' && $this->hasOccupancy
            ? 'occupancy'
            : (in_array($this->chartMetric, ['unique', 'exits'], true) ? $this->chartMetric : 'visits');

        $current = $metric === 'occupancy'
            ? $this->occupancy()->series($this->range())
            : $this->analytics()->seriesOverTime($this->range(), $metric);

        $previous = collect();

        if ($this->comparison) {
            $previous = $metric === 'occupancy'
                ? $this->occupancy()->series($this->comparison)
                : $this->analytics()->seriesOverTime($this->comparison, $metric);
        }

        $paired = $current->values()->map(function (array $point, int $index) use ($previous): array {
            return [
                ...$point,
                'previous' => (int) ($previous[$index]['count'] ?? 0),
            ];
        });

        return [
            'labels' => $paired->pluck('label')->all(),
            'dates' => $paired->pluck('date')->all(),
            'current' => $paired->pluck('count')->all(),
            'previous' => $paired->pluck('previous')->all(),
        ];
    }

    #[Computed]
    public function dayContext(): Collection
    {
        return app(DayContextAnalytics::class)->forRange($this->range());
    }

    /**
     * @return array<string, array<int, string>>
     */
    #[Computed]
    public function dayAnnotations(): array
    {
        $out = [];

        foreach ($this->trend['dates'] as $index => $iso) {
            $day = strlen($iso) > 10 ? substr($iso, 0, 10) : $iso;
            $ctx = $this->dayContext->get($day);

            if ($ctx === null) {
                continue;
            }

            $lines = [];

            if ($ctx['is_public_holiday']) {
                $lines[] = 'Public holiday'.($ctx['holiday_name'] ? ': '.$ctx['holiday_name'] : '');
            }

            if ($ctx['is_school_holiday']) {
                $lines[] = 'School holiday';
            }

            if (($ctx['temp_avg_c'] ?? null) !== null && (float) $ctx['temp_avg_c'] >= 30) {
                $lines[] = 'High temperature · '.round((float) $ctx['temp_avg_c']).'°C';
            }

            if ($ctx['weather_label'] !== null && $ctx['weather_label'] !== 'Clear') {
                $lines[] = $ctx['weather_label'];
            }

            if ($lines !== []) {
                $out[$this->trend['labels'][$index]] = $lines;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{label: string, kind: string, text: string}>
     */
    #[Computed]
    public function notableDays(): array
    {
        $chips = [];

        foreach ($this->trend['dates'] as $index => $iso) {
            $day = strlen($iso) > 10 ? substr($iso, 0, 10) : $iso;
            $ctx = $this->dayContext->get($day);

            if ($ctx === null) {
                continue;
            }

            $label = $this->trend['labels'][$index];

            if ($ctx['is_public_holiday']) {
                $chips[] = [
                    'label' => $label,
                    'kind' => 'holiday',
                    'text' => $ctx['holiday_name'] ?? 'Public holiday',
                ];
            }

            if ($ctx['weather_label'] !== null && in_array($ctx['weather_label'], ['Rain', 'Thunderstorm', 'Snow'], true)) {
                $chips[] = [
                    'label' => $label,
                    'kind' => 'weather',
                    'text' => $ctx['weather_label'],
                ];
            }
        }

        return $chips;
    }

    /**
     * @return Collection<int, array{weekday: int, label: string, hours: array<int, array{hour: int, average: float}>}>
     */
    #[Computed]
    public function heatmap(): Collection
    {
        return $this->analytics()->dayHourHeatmap($this->range());
    }

    #[Computed]
    public function heatmapMax(): float
    {
        return (float) collect($this->heatmap)->max(fn (array $row) => collect($row['hours'])->max('average')) ?: 0;
    }

    #[Computed]
    public function occupancySummary(): ?array
    {
        return $this->hasOccupancy ? $this->occupancy()->summary($this->range()) : null;
    }

    #[Computed]
    public function securitySummary(): array
    {
        return app(SecurityAnalytics::class)->reportSummary($this->range());
    }

    #[Computed]
    public function quality(): array
    {
        return app(DataQualityAnalytics::class)->summary($this->range());
    }

    /**
     * @return array{total: int, daily_average: int, busiest: array<string, mixed>|null, average_dwell: int|null, median_dwell: int|null}
     */
    #[Computed]
    public function summary(): array
    {
        $range = $this->range();
        $dwell = $this->analytics()->dwellSummary($range);
        $total = $this->analytics()->totalVisits($range);

        return [
            'total' => $total,
            'daily_average' => (int) round($total / max(1, $this->daily()->count())),
            'busiest' => $this->daily()->sortByDesc('count')->first(),
            'average_dwell' => $dwell['average'],
            'median_dwell' => $dwell['median'],
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        return app(ReportExporter::class)->csvDownload($this->report());
    }

    public function exportPdf(): StreamedResponse
    {
        return app(ReportExporter::class)->pdfDownload($this->report());
    }

    protected function report(): TrafficReport
    {
        return new TrafficReport(
            $this->analytics(),
            $this->range(),
            app(Tenancy::class)->currentSite()?->name ?? 'All sites',
            $this->comparison(),
            $this->canSeeOps(),
            $this->occupancySummary,
        );
    }

    public function isShop(): bool
    {
        return app(Tenancy::class)->isShop();
    }

    protected function normaliseSection(): void
    {
        if (! array_key_exists($this->section, $this->sections())) {
            $this->section = 'overview';
        }
    }

    protected function normaliseMetric(): void
    {
        $allowed = ['visits', 'unique', 'entries', 'exits'];

        if ($this->hasOccupancy()) {
            $allowed[] = 'occupancy';
        }

        if (! in_array($this->chartMetric, $allowed, true)) {
            $this->chartMetric = 'visits';
        }
    }

    /**
     * @return array{label: string, value: string, icon: string, delta: string|null, tone: string, comparison: string|null}
     */
    protected function kpi(string $label, string $value, int|float|null $current, int|float|null $previous, ?string $caption = null, string $icon = 'chart-bar'): array
    {
        $compare = $this->compareKey === 'none'
            ? ['label' => null, 'tone' => 'muted']
            : $this->analytics()->comparison($current, $previous);

        return [
            'label' => $label,
            'value' => $value,
            'icon' => $icon,
            'delta' => $compare['label'] === 'No prior data' ? null : $compare['label'],
            'tone' => $compare['tone'] === 'up' || $compare['tone'] === 'down' ? $compare['tone'] : 'muted',
            'comparison' => $caption ?? ($this->compareKey === 'none' || $compare['label'] === null
                ? null
                : 'vs '.strtolower(DateRange::comparisonOptions()[$this->compareKey] ?? 'previous period')),
        ];
    }
}; ?>

<div>
    <x-dashboard-header
        title="Reports"
        :subtitle="(app(Tenancy::class)->currentSite()?->name ?? 'All sites').' · '.strtolower($this->range->label)"
        :show-bell="false"
    >
        <x-slot:actions>
            @if (app(Tenancy::class)->hasMultipleSites())
                <livewire:site-switcher :key="'reports-site'" />
            @endif

            <flux:select wire:model.live="rangeKey" size="sm" class="min-w-40" icon="calendar" label="Period" label:sr-only>
                @foreach (DateRange::reportOptions() as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($rangeKey === 'custom')
                <flux:input type="date" wire:model.live="fromDate" size="sm" label="From" label:sr-only />
                <flux:input type="date" wire:model.live="toDate" size="sm" label="To" label:sr-only />
            @endif

            <flux:select wire:model.live="compareKey" size="sm" class="min-w-44" label="Compare" label:sr-only>
                @foreach (DateRange::comparisonOptions() as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            @unless ($this->isShop())
                <flux:select wire:model.live="audience" size="sm" class="min-w-44" label="Visitors" label:sr-only>
                    @foreach (DateRange::audienceOptions() as $key => $label)
                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endunless

            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" wire:click="exportCsv">CSV</flux:button>
            <flux:button size="sm" variant="ghost" icon="document-text" wire:click="exportPdf">PDF</flux:button>
        </x-slot:actions>
    </x-dashboard-header>

    <div class="mb-6 inline-flex flex-wrap gap-1 rounded-tf border border-line bg-surface p-1 shadow-tf-sm">
        @foreach ($this->sections as $key => $label)
            <button
                type="button"
                wire:click="$set('section', '{{ $key }}')"
                @class([
                    'rounded-md px-3 py-1.5 text-[12px] font-semibold transition-colors',
                    'bg-accent text-white shadow-tf-sm' => $section === $key,
                    'text-ink-2 hover:bg-surface-2 hover:text-ink' => $section !== $key,
                ])
            >{{ $label }}</button>
        @endforeach
    </div>

    @if (in_array($section, ['overview', 'visits'], true))
        {{-- Primary KPI row — the five figures a landlord opens Reports for.
             Kept intentionally short so Visits and Unique Visitors get room
             to breathe; everything supporting sits in the compact secondary
             strip below. --}}
        <div class="mb-4 grid grid-cols-5 gap-4 max-xl:grid-cols-3 max-lg:grid-cols-2 max-sm:grid-cols-1">
            @foreach ($this->kpis as $card)
                <x-kpi-card
                    :label="$card['label']"
                    :value="$card['value']"
                    :icon="$card['icon']"
                    :delta="$card['delta']"
                    :delta-tone="$card['tone']"
                    :comparison="$card['comparison']"
                />
            @endforeach
        </div>

        <div class="mb-6 grid grid-cols-5 gap-3 max-xl:grid-cols-3 max-md:grid-cols-2 max-sm:grid-cols-1">
            @foreach ($this->secondaryKpis as $secondary)
                <div class="rounded-tf border border-line bg-surface-2 px-3 py-2.5">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-muted">{{ $secondary['label'] }}</p>
                    <p class="mt-1 text-[15px] font-semibold text-ink tabular-nums">{{ $secondary['value'] }}</p>
                    @if ($secondary['detail'])
                        <p class="text-[11.5px] text-ink-muted">{{ $secondary['detail'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($section === 'overview' || $section === 'visits')
        <x-panel-card class="mb-6">
            <x-slot:header>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Visits per day</p>
                    <p class="mt-1 text-sm text-ink-2">
                        {{ $this->compareKey === 'none' ? 'Selected period' : 'This period vs '.strtolower(DateRange::comparisonOptions()[$compareKey]) }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <flux:select wire:model.live="chartMetric" size="sm" class="min-w-40" label="Metric" label:sr-only>
                        <flux:select.option value="visits">Visits</flux:select.option>
                        <flux:select.option value="unique">Unique visitors</flux:select.option>
                        <flux:select.option value="entries">Entries</flux:select.option>
                        <flux:select.option value="exits">Exits</flux:select.option>
                        @if ($this->hasOccupancy)
                            <flux:select.option value="occupancy">Occupancy</flux:select.option>
                        @endif
                    </flux:select>
                    <span class="rounded-full bg-surface-2 px-3 py-1 text-[11px] font-medium text-ink-2">
                        {{ $this->range->grain() === 'hour' ? 'Hourly' : ($this->range->grain() === 'week' ? 'Weekly' : 'Daily') }}
                    </span>
                </div>
            </x-slot:header>

            @if ($this->compareKey !== 'none')
                <x-chart
                    :labels="$this->trend['labels']"
                    :series="[
                        ['label' => 'This period', 'values' => $this->trend['current'], 'color' => 'accent'],
                        ['label' => 'Comparison', 'values' => $this->trend['previous'], 'color' => 'accentSoft'],
                    ]"
                    :annotations="$this->dayAnnotations"
                    :show-legend="true"
                    :height="240"
                    aria-label="Visits over time compared with the selected period"
                />
            @else
                <x-chart
                    :labels="$this->trend['labels']"
                    :values="$this->trend['current']"
                    :annotations="$this->dayAnnotations"
                    :height="240"
                    aria-label="Visits over time"
                />
            @endif

            @if (! empty($this->notableDays))
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($this->notableDays as $chip)
                        <span @class([
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                            'bg-warning-soft text-warning' => $chip['kind'] === 'holiday',
                            'bg-accent-soft text-accent' => $chip['kind'] === 'weather',
                        ])>
                            <flux:icon :icon="$chip['kind'] === 'holiday' ? 'calendar-days' : 'cloud'" class="size-3" />
                            <span class="tabular-nums font-semibold">{{ $chip['label'] }}</span>
                            <span>·</span>
                            <span>{{ $chip['text'] }}</span>
                        </span>
                    @endforeach
                </div>
            @endif
        </x-panel-card>
    @endif

    @if ($section === 'overview' && $this->canSeeOps)
        <x-panel-card class="mb-6">
            <x-slot:header>
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-accent-soft text-accent">
                        <flux:icon icon="signal" class="size-4" />
                    </span>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Visit pairing quality</p>
                        <p class="mt-1 text-sm text-ink-2">How cleanly entry and exit reads became visits</p>
                    </div>
                </div>
            </x-slot:header>
            <p class="text-[30px] font-semibold leading-none tracking-tight text-ink">
                {{ $this->quality['pairing_quality'] === null ? '—' : $this->quality['pairing_quality'].'%' }}
            </p>
            <p class="mt-2 text-[13px] text-ink-2">
                {{ number_format($this->quality['reads']) }} reads → {{ number_format($this->quality['paired_visits']) }} paired visits
                · {{ number_format($this->quality['unmatched_reads']) }} unmatched reads
            </p>
        </x-panel-card>
    @endif

    @if ($section === 'overview')
        <div class="mb-6 grid grid-cols-2 gap-4 max-md:grid-cols-1">
            <x-panel-card>
                <x-slot:header>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Total visits by hour</p>
                        <p class="mt-1 text-sm text-ink-2">Every hour, added up across the whole period</p>
                    </div>
                </x-slot:header>
                <x-chart
                    :labels="$this->analytics->visitsByHour($this->range)->pluck('label')->all()"
                    :values="$this->analytics->visitsByHour($this->range)->pluck('count')->all()"
                    :height="200"
                    aria-label="Bar chart of vehicle visits by hour"
                />
            </x-panel-card>

            <x-panel-card>
                <x-slot:header>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Dwell time distribution</p>
                        <p class="mt-1 text-sm text-ink-2">Closed visits only</p>
                    </div>
                </x-slot:header>
                <x-data-table :headers="['Duration', ['label' => 'Visits', 'align' => 'right'], ['label' => 'Share', 'align' => 'right']]">
                    @foreach ($this->analytics->dwellDistribution($this->range) as $bucket)
                        <tr wire:key="bucket-{{ $bucket['label'] }}">
                            <td class="border-b border-line py-2">{{ $bucket['label'] }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($bucket['count']) }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">{{ $bucket['percent'] }}%</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-panel-card>
        </div>

        <x-panel-card>
            <x-slot:header>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Daily breakdown</p>
                    <p class="mt-1 text-sm text-ink-2">Every day in the selected window</p>
                </div>
            </x-slot:header>
            <x-data-table
                :headers="['Day', ['label' => 'Visits', 'align' => 'right']]"
                :is-empty="$this->daily->isEmpty()"
            >
                @foreach ($this->daily->reverse() as $day)
                    <tr wire:key="day-{{ $day['date'] }}">
                        <td class="border-b border-line py-2">{{ $day['label'] }}</td>
                        <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($day['count']) }}</td>
                    </tr>
                @endforeach
            </x-data-table>
        </x-panel-card>
    @endif

    @if ($section === 'visits')
        <x-panel-card class="mb-6">
            <x-slot:header>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Day and hour</p>
                    <p class="mt-1 text-sm text-ink-2">Average visits for each weekday and hour</p>
                </div>
                <div class="flex items-center gap-2 text-[11px] text-ink-muted">
                    <span class="size-2.5 rounded-sm bg-accent/20"></span>
                    Quiet
                    <span class="size-2.5 rounded-sm bg-accent"></span>
                    Busy
                </div>
            </x-slot:header>
            <x-heatmap :rows="$this->heatmap" :max="$this->heatmapMax" />
        </x-panel-card>

        <x-panel-card>
            <x-slot:header>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Visits by day of week</p>
                    <p class="mt-1 text-sm text-ink-2">Average visits per occurrence of that weekday</p>
                </div>
            </x-slot:header>
            <x-chart
                :labels="$this->analytics->visitsByWeekday($this->range)->pluck('label')->all()"
                :values="$this->analytics->visitsByWeekday($this->range)->pluck('count')->all()"
                :height="220"
                aria-label="Average visits by weekday"
            />
        </x-panel-card>
    @endif

    @if ($section === 'dwell')
        <div class="mb-6 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
            <x-kpi-card
                label="Avg dwell"
                :value="$this->summary['average_dwell'] === null ? '—' : $this->summary['average_dwell'].' min'"
                icon="clock"
            />
            <x-kpi-card
                label="Median dwell"
                :value="$this->summary['median_dwell'] === null ? '—' : $this->summary['median_dwell'].' min'"
                icon="clock"
            />
        </div>

        <div class="grid grid-cols-2 gap-4 max-md:grid-cols-1">
            <x-panel-card>
                <x-slot:header>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Dwell distribution</p>
                        <p class="mt-1 text-sm text-ink-2">How long visitors stayed</p>
                    </div>
                </x-slot:header>
                <x-chart
                    :labels="$this->analytics->dwellDistribution($this->range)->pluck('label')->all()"
                    :values="$this->analytics->dwellDistribution($this->range)->pluck('count')->all()"
                    :height="220"
                    aria-label="Visit counts by dwell bucket"
                />
            </x-panel-card>

            <x-panel-card>
                <x-slot:header>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Share of visits</p>
                        <p class="mt-1 text-sm text-ink-2">Closed visits only</p>
                    </div>
                </x-slot:header>
                <x-data-table :headers="['Duration', ['label' => 'Visits', 'align' => 'right'], ['label' => 'Share', 'align' => 'right']]">
                    @foreach ($this->analytics->dwellDistribution($this->range) as $bucket)
                        <tr wire:key="dwell-{{ $bucket['label'] }}">
                            <td class="border-b border-line py-2">{{ $bucket['label'] }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($bucket['count']) }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">{{ $bucket['percent'] }}%</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-panel-card>
        </div>
    @endif

    @if ($section === 'behaviour')
        <div class="mb-6 grid grid-cols-3 gap-4 max-sm:grid-cols-1">
            <x-kpi-card label="First-time visitors" :value="number_format($this->analytics->firstTimeVehicles($this->range))" icon="user-group" />
            <x-kpi-card label="Returning visitors" :value="number_format($this->analytics->returningVehicles($this->range))" icon="arrow-path" />
            <x-kpi-card
                label="Return rate"
                :value="$this->analytics->returningVehicleRate($this->range) === null ? '—' : $this->analytics->returningVehicleRate($this->range).'%'"
                icon="arrow-path"
                :comparison="$this->analytics->returnRate30Day($this->range) === null ? null : '30-day return rate: '.$this->analytics->returnRate30Day($this->range).'%'"
            />
        </div>

        <div class="grid grid-cols-2 gap-4 max-md:grid-cols-1">
            <x-panel-card>
                <x-slot:header>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Visit frequency</p>
                        <p class="mt-1 text-sm text-ink-2">How often the same vehicle came back</p>
                    </div>
                </x-slot:header>
                <x-chart
                    :labels="$this->analytics->visitFrequency($this->range)->pluck('label')->all()"
                    :values="$this->analytics->visitFrequency($this->range)->pluck('count')->all()"
                    :height="220"
                    aria-label="Unique visitors by visit frequency"
                />
            </x-panel-card>

            <x-panel-card>
                <x-slot:header>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Frequency breakdown</p>
                        <p class="mt-1 text-sm text-ink-2">Unique visitors, not plates</p>
                    </div>
                </x-slot:header>
                <x-data-table :headers="['Visits', ['label' => 'Visitors', 'align' => 'right'], ['label' => 'Share', 'align' => 'right']]">
                    @foreach ($this->analytics->visitFrequency($this->range) as $bucket)
                        <tr wire:key="freq-{{ $bucket['label'] }}">
                            <td class="border-b border-line py-2">{{ $bucket['label'] }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($bucket['count']) }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">{{ $bucket['percent'] }}%</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-panel-card>
        </div>
    @endif

    @if ($section === 'occupancy' && $this->occupancySummary)
        <div class="mb-6 grid grid-cols-4 gap-4 max-lg:grid-cols-2 max-sm:grid-cols-1">
            <x-kpi-card label="Average occupancy" :value="number_format($this->occupancySummary['average'], 1)" icon="map-pin" />
            <x-kpi-card label="Peak occupancy" :value="number_format($this->occupancySummary['peak'])" icon="chart-bar" />
            <x-kpi-card
                label="Time peak occurred"
                :value="$this->occupancySummary['peak_at'] ? \Illuminate\Support\Facades\Date::parse($this->occupancySummary['peak_at'])->format('j M H:i') : '—'"
                icon="clock"
            />
            <x-kpi-card
                label="Parking pressure"
                :value="$this->occupancySummary['parking_pressure'].' above 80%'"
                icon="bell-alert"
                :comparison="'Above 90%: '.\App\Support\Analytics\OccupancyAnalytics::formatDuration($this->occupancySummary['minutes_above_90'])"
            />
        </div>

        <x-panel-card>
            <x-slot:header>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Occupancy over time</p>
                    <p class="mt-1 text-sm text-ink-2">Vehicles on site from entry and exit state</p>
                </div>
            </x-slot:header>
            <x-chart
                :labels="$this->occupancy->series($this->range)->pluck('label')->all()"
                :values="$this->occupancy->series($this->range)->pluck('count')->all()"
                :height="240"
                aria-label="Occupancy over time"
            />
        </x-panel-card>
    @endif

    @if ($section === 'security' && $this->canSeeOps)
        <div class="mb-6 grid grid-cols-3 gap-4 max-lg:grid-cols-2 max-sm:grid-cols-1">
            <x-kpi-card label="Watchlist hits" :value="number_format($this->securitySummary['watchlist_hits'])" icon="shield-exclamation" />
            <x-kpi-card label="Long-dwell alerts" :value="number_format($this->securitySummary['long_dwell'])" icon="clock" />
            <x-kpi-card label="Odd-hour activity" :value="number_format($this->securitySummary['odd_hour'])" icon="clock" />
            <x-kpi-card label="Multiple-entry vehicles" :value="number_format($this->securitySummary['multi_entry'])" icon="arrow-path" />
            <x-kpi-card label="Missed exits / orphan visits" :value="number_format($this->securitySummary['orphaned'])" icon="information-circle" />
            <x-kpi-card label="Cameras currently offline" :value="number_format($this->securitySummary['cameras_offline'])" icon="video-camera" />
        </div>

        <div class="grid grid-cols-2 gap-4 max-md:grid-cols-1">
            <x-panel-card>
                <x-slot:header>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Incidents by day</p>
                        <p class="mt-1 text-sm text-ink-2">Alert volume across the period</p>
                    </div>
                </x-slot:header>
                <x-chart
                    :labels="app(\App\Support\Analytics\SecurityAnalytics::class)->incidentsByDay($this->range)->pluck('label')->all()"
                    :values="app(\App\Support\Analytics\SecurityAnalytics::class)->incidentsByDay($this->range)->pluck('count')->all()"
                    color="danger"
                    :height="220"
                    aria-label="Security incidents by day"
                />
            </x-panel-card>

            <x-panel-card>
                <x-slot:header>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Incidents by type</p>
                        <p class="mt-1 text-sm text-ink-2">Watchlist, dwell, odd-hour, multi-entry</p>
                    </div>
                </x-slot:header>
                <x-chart
                    :labels="app(\App\Support\Analytics\SecurityAnalytics::class)->incidentsByType($this->range)->pluck('label')->all()"
                    :values="app(\App\Support\Analytics\SecurityAnalytics::class)->incidentsByType($this->range)->pluck('count')->all()"
                    color="warning"
                    :height="220"
                    aria-label="Security incidents by type"
                />
            </x-panel-card>
        </div>
    @endif

    @if ($section === 'quality' && $this->canSeeOps)
        <x-panel-card class="mb-6">
            <x-slot:header>
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-accent-soft text-accent">
                        <flux:icon icon="signal" class="size-4" />
                    </span>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Visit pairing quality</p>
                        <p class="mt-1 text-sm text-ink-2">How cleanly entry and exit reads became visits</p>
                    </div>
                </div>
            </x-slot:header>
            <p class="text-[30px] font-semibold leading-none tracking-tight text-ink">
                {{ $this->quality['pairing_quality'] === null ? '—' : $this->quality['pairing_quality'].'%' }}
            </p>
            <p class="mt-2 text-[13px] text-ink-2">
                {{ number_format($this->quality['reads']) }} reads → {{ number_format($this->quality['paired_visits']) }} paired visits
                · {{ number_format($this->quality['unmatched_reads']) }} unmatched reads
            </p>
        </x-panel-card>

        <div class="grid grid-cols-4 gap-4 max-lg:grid-cols-2 max-sm:grid-cols-1">
            <x-kpi-card label="Plate reads received" :value="number_format($this->quality['reads'])" icon="bars-3" />
            <x-kpi-card label="Entries" :value="number_format($this->quality['entries'])" icon="arrow-right" />
            <x-kpi-card label="Exits" :value="number_format($this->quality['exits'])" icon="arrow-left" />
            <x-kpi-card label="Successfully paired visits" :value="number_format($this->quality['paired_visits'])" icon="check-circle" />
            <x-kpi-card label="Orphan entries" :value="number_format($this->quality['orphan_entries'])" icon="information-circle" />
            <x-kpi-card label="Orphan exits" :value="number_format($this->quality['orphan_exits'])" icon="information-circle" />
            <x-kpi-card label="Camera uptime" :value="$this->quality['camera_uptime'] === null ? '—' : $this->quality['camera_uptime'].'%'" icon="signal" />
            <x-kpi-card label="Cameras currently offline" :value="number_format($this->quality['cameras_offline'])" icon="video-camera" />
        </div>
    @endif
</div>
