<?php

use App\Models\Visit;
use App\Support\Analytics\DataQualityAnalytics;
use App\Support\Analytics\DateRange;
use App\Support\Analytics\DayContextAnalytics;
use App\Support\Analytics\OccupancyAnalytics;
use App\Support\Analytics\SecurityAnalytics;
use App\Support\Analytics\TrafficAnalytics;
use App\Support\Analytics\WeatherImpactAnalytics;
use App\Support\Reporting\ReportExporter;
use App\Support\Reporting\TrafficReport;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Reports')] class extends Component {
    /**
     * Where the six pre-redesign sections now live, so bookmarked
     * `?section=` links still open the matching tab.
     */
    public const LEGACY_SECTIONS = [
        'overview' => 'summary',
        'visits' => 'traffic',
        'occupancy' => 'traffic',
        'dwell' => 'behaviour',
        'behaviour' => 'behaviour',
        'security' => 'security',
        'quality' => 'health',
    ];

    public const DAILY_PAGE_SIZE = 10;

    #[Url(as: 'range', keep: true)]
    public string $rangeKey = '30d';

    #[Url(as: 'compare', keep: true)]
    public string $compareKey = 'previous';

    #[Url(as: 'audience', keep: true)]
    public string $audience = 'shopper';

    #[Url(as: 'tab', keep: true)]
    public string $tab = 'summary';

    #[Url(as: 'metric', keep: true)]
    public string $chartMetric = 'visits';

    #[Url(as: 'from', keep: true)]
    public ?string $fromDate = null;

    #[Url(as: 'to', keep: true)]
    public ?string $toDate = null;

    /**
     * Drops wet days ({@see DayContextAnalytics::WET_LABELS}) from the daily
     * trend chart only. Totals, other charts and exports are unaffected.
     */
    #[Url(as: 'exclude_wet', keep: true)]
    public bool $excludeWet = false;

    /**
     * Drops public holidays from the daily trend chart only, so a run of
     * holidays doesn't make an ordinary week look thin.
     */
    #[Url(as: 'exclude_holidays', keep: true)]
    public bool $excludeHolidays = false;

    public int $dailyPage = 1;

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

        // Once the page has written `tab` into the URL it wins, so a reload
        // after switching tabs doesn't snap back to the old `section` link.
        $legacy = request()->query('section');

        if (! request()->has('tab') && is_string($legacy) && isset(self::LEGACY_SECTIONS[$legacy])) {
            $this->tab = self::LEGACY_SECTIONS[$legacy];
        }

        $this->normaliseTab();
        $this->normaliseMetric();
    }

    public function updatedRangeKey(): void
    {
        if ($this->rangeKey === 'custom') {
            $this->fromDate ??= now()->subDays(29)->toDateString();
            $this->toDate ??= now()->toDateString();
        }

        $this->dailyPage = 1;
    }

    public function updatedFromDate(): void
    {
        $this->dailyPage = 1;
    }

    public function updatedToDate(): void
    {
        $this->dailyPage = 1;
    }

    public function updatedTab(): void
    {
        $this->normaliseTab();
    }

    public function updatedChartMetric(): void
    {
        $this->normaliseMetric();
    }

    public function previousDailyPage(): void
    {
        $this->dailyPage = max(1, $this->dailyPage - 1);
    }

    public function nextDailyPage(): void
    {
        $this->dailyPage = min($this->dailyPageCount, $this->dailyPage + 1);
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

    /**
     * The comparison window, cut to the same elapsed time while the selected
     * period is still running so a partial day never faces a full one.
     */
    #[Computed]
    public function comparison(): ?DateRange
    {
        return $this->range()->elapsedComparisonRange($this->compareKey);
    }

    #[Computed]
    public function comparisonCaption(): ?string
    {
        if ($this->comparison === null) {
            return null;
        }

        $label = 'vs '.strtolower(DateRange::comparisonOptions()[$this->compareKey] ?? 'previous period');

        return $this->range->isInProgress() ? $label.' to the same point' : $label;
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
    public function canManageSchedule(): bool
    {
        return auth()->user()->isOwnerAdmin();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        $items = [
            'summary' => 'Summary',
            'traffic' => 'Traffic patterns',
            'behaviour' => 'Visit behaviour',
        ];

        if ($this->canSeeOps) {
            $items['security'] = 'Security';
            $items['health'] = 'System health';
        }

        return $items;
    }

    #[Computed]
    public function daily(): Collection
    {
        return $this->analytics()->visitsByDay($this->range());
    }

    #[Computed]
    public function dailyPageCount(): int
    {
        return max(1, (int) ceil($this->daily->count() / self::DAILY_PAGE_SIZE));
    }

    /**
     * Newest day first, one page at a time. Exports still carry every day.
     */
    #[Computed]
    public function dailyRows(): Collection
    {
        $page = min(max(1, $this->dailyPage), $this->dailyPageCount);

        return $this->daily->reverse()->values()->forPage($page, self::DAILY_PAGE_SIZE)->values();
    }

    #[Computed]
    public function dwell(): array
    {
        return $this->analytics()->dwellSummary($this->range());
    }

    #[Computed]
    public function lowStaySample(): bool
    {
        return TrafficAnalytics::isLowStaySample($this->dwell['sample']);
    }

    #[Computed]
    public function unique(): int
    {
        return $this->analytics()->uniqueVehicles($this->range());
    }

    #[Computed]
    public function returning(): ?int
    {
        return $this->analytics()->returningVehicles($this->range());
    }

    #[Computed]
    public function returnRate(): ?float
    {
        return $this->analytics()->returningVehicleRate($this->range());
    }

    /**
     * Summary headline: visits, unique vehicles, returning share and typical
     * stay. Return rate keeps the visitor-based definition used everywhere.
     *
     * @return array<int, array{label: string, value: string, icon: string, delta: string|null, comparison: string|null, warning: string|null}>
     */
    #[Computed]
    public function summaryCards(): array
    {
        $range = $this->range();
        $previous = $this->comparison();
        $a = $this->analytics();

        $visits = $a->totalVisits($range);

        return [
            $this->card(
                'Visits',
                number_format($visits),
                'arrow-right-end-on-rectangle',
                $this->delta($visits, $previous ? $a->totalVisits($previous) : null),
                'Every arrival, including repeat trips',
            ),
            $this->card(
                'Unique vehicles',
                number_format($this->unique),
                'truck',
                $this->delta($this->unique, $previous ? $a->uniqueVehicles($previous) : null),
                'Distinct registrations detected',
            ),
            $this->card(
                'Returning share',
                $this->returnRate === null ? '—' : $this->returnRate.'%',
                'arrow-path',
                $this->returnRate === null ? null : $this->delta($this->returnRate, $previous ? $a->returningVehicleRate($previous) : null),
                $this->returnRate === null
                    ? 'Not enough history before this period yet'
                    : number_format($this->returning ?? 0).' of '.number_format($this->unique).' vehicles were seen before this period',
            ),
            $this->stayCard(),
        ];
    }

    /**
     * @return array{label: string, value: string, icon: string, delta: string|null, comparison: string|null, warning: string|null}
     */
    protected function stayCard(): array
    {
        $dwell = $this->dwell;

        if ($dwell['sample'] === 0 || $dwell['median'] === null) {
            return $this->card('Typical stay', '—', 'clock', null, 'No completed visits with a matched exit in this period');
        }

        $basis = 'Median of '.number_format($dwell['sample']).' completed '.Str::plural('visit', $dwell['sample']);

        if ($this->lowStaySample) {
            return $this->card('Typical stay', $dwell['median'].' min', 'clock', null, $basis, 'Low sample — not compared with the previous period.');
        }

        $previous = $this->comparison ? $this->analytics()->dwellSummary($this->comparison) : null;
        $delta = $previous === null || TrafficAnalytics::isLowStaySample($previous['sample'])
            ? null
            : $this->delta($dwell['median'], $previous['median']);

        return $this->card('Typical stay', $dwell['median'].' min', 'clock', $delta, $basis.' · average '.$dwell['average'].' min');
    }

    /**
     * @return array{busiest: array<string, mixed>|null, peak: array<string, mixed>|null, daily_average: int, excluded: int}
     */
    #[Computed]
    public function highlights(): array
    {
        $visits = $this->daily->sum('count');

        return [
            'busiest' => $visits > 0 ? $this->daily->sortByDesc('count')->first() : null,
            'peak' => $this->analytics()->peakHour($this->range()),
            'daily_average' => (int) round($visits / max(1, $this->daily->count())),
            'excluded' => $this->analytics()->excludedVisitCount($this->range()),
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

        // The chart pairs each bucket with the same bucket of the full
        // comparison window, so it uses the un-cut window; the headline
        // figures above use the elapsed-matched one.
        $chartComparison = $this->range()->comparisonRange($this->compareKey);
        $previous = collect();

        if ($chartComparison) {
            $previous = $metric === 'occupancy'
                ? $this->occupancy()->series($chartComparison)
                : $this->analytics()->seriesOverTime($chartComparison, $metric);
        }

        $now = now();
        $paired = $current->values()->map(function (array $point, int $index) use ($previous, $now): array {
            $isFuture = Date::parse($point['date'])->gt($now);

            return [
                ...$point,
                // Buckets that have not started are gaps, not measured zeroes.
                'count' => $isFuture ? null : $point['count'],
                'previous' => (int) ($previous[$index]['count'] ?? 0),
            ];
        });

        // Exclusions only make sense at daily grain: an hourly chart lives
        // inside one day, and a weekly bar can't be split by daily weather.
        if ($this->range()->grain() === 'day') {
            $context = app(DayContextAnalytics::class);
            $skip = [];

            if ($this->excludeWet) {
                $skip += array_flip($context->wetDates($this->range()));
            }

            if ($this->excludeHolidays) {
                $skip += array_flip($context->publicHolidayDates($this->range()));
            }

            if ($skip !== []) {
                $paired = $paired
                    ->reject(fn (array $day) => isset($skip[substr((string) $day['date'], 0, 10)]))
                    ->values();
            }
        }

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
     * Weather-vs-visits comparison, or null when there is no weather data
     * for this range at all.
     *
     * @return array{has_enough_data: bool, wet_days_count: int, dry_days_count: int, wet_avg_visits: int|null, dry_avg_visits: int|null, delta_percent: float|null}|null
     */
    #[Computed]
    public function weatherImpact(): ?array
    {
        return app(WeatherImpactAnalytics::class)->forRange($this->range());
    }

    /**
     * Tooltip lines for the trend chart, keyed by its labels.
     *
     * @return array<string, array<int, string>>
     */
    #[Computed]
    public function dayAnnotations(): array
    {
        $out = [];

        foreach ($this->trend['dates'] as $index => $iso) {
            $ctx = $this->dayContext->get(substr((string) $iso, 0, 10));

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
     * Holidays and wet days in the trend, listed on demand under the chart.
     *
     * @return array<int, array{label: string, kind: string, text: string}>
     */
    #[Computed]
    public function notableDays(): array
    {
        $chips = [];

        foreach ($this->trend['dates'] as $index => $iso) {
            $ctx = $this->dayContext->get(substr((string) $iso, 0, 10));

            if ($ctx === null) {
                continue;
            }

            $label = $this->trend['labels'][$index];

            if ($ctx['is_public_holiday']) {
                $chips[] = ['label' => $label, 'kind' => 'holiday', 'text' => $ctx['holiday_name'] ?? 'Public holiday'];
            }

            if ($ctx['weather_label'] !== null && in_array($ctx['weather_label'], DayContextAnalytics::WET_LABELS, true)) {
                $chips[] = ['label' => $label, 'kind' => 'weather', 'text' => $ctx['weather_label']];
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
    public function hourly(): Collection
    {
        return $this->analytics()->visitsByHour($this->range());
    }

    #[Computed]
    public function weekday(): Collection
    {
        return $this->analytics()->visitsByWeekday($this->range());
    }

    #[Computed]
    public function entryPoints(): Collection
    {
        return $this->analytics()->topEntryPoints($this->range(), 10);
    }

    #[Computed]
    public function frequency(): Collection
    {
        return $this->analytics()->visitFrequency($this->range());
    }

    #[Computed]
    public function stayDistribution(): Collection
    {
        return $this->analytics()->dwellDistribution($this->range());
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
    public function incidentTotal(): int
    {
        $s = $this->securitySummary;

        return $s['watchlist_hits'] + $s['long_dwell'] + $s['odd_hour'] + $s['multi_entry'];
    }

    #[Computed]
    public function quality(): array
    {
        return app(DataQualityAnalytics::class)->summary($this->range());
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

    protected function normaliseTab(): void
    {
        $this->tab = self::LEGACY_SECTIONS[$this->tab] ?? $this->tab;

        if (! array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'summary';
        }
    }

    protected function normaliseMetric(): void
    {
        $allowed = ['visits', 'unique', 'exits'];

        if ($this->hasOccupancy()) {
            $allowed[] = 'occupancy';
        }

        if (! in_array($this->chartMetric, $allowed, true)) {
            $this->chartMetric = 'visits';
        }
    }

    protected function delta(int|float|null $current, int|float|null $previous): ?string
    {
        if ($this->comparison === null) {
            return null;
        }

        $compare = $this->analytics()->comparison($current, $previous);

        return $compare['label'] === 'No prior data' ? null : $compare['label'];
    }

    /**
     * @return array{label: string, value: string, icon: string, delta: string|null, comparison: string|null, warning: string|null}
     */
    protected function card(string $label, string $value, string $icon, ?string $delta, ?string $caption, ?string $warning = null): array
    {
        $comparison = $caption;

        if ($delta !== null && $this->comparisonCaption !== null) {
            $comparison = $this->comparisonCaption.($caption ? ' · '.$caption : '');
        }

        return [
            'label' => $label,
            'value' => $value,
            'icon' => $icon,
            'delta' => $delta,
            'comparison' => $comparison,
            'warning' => $warning,
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
            @if ($this->canManageSchedule)
                <flux:button variant="ghost" icon="envelope" :href="route('settings', ['tab' => 'reports'])" wire:navigate class="min-h-11">Scheduled reports</flux:button>
            @endif
            <x-export-menu />
        </x-slot:actions>
    </x-dashboard-header>

    {{-- Shared filters. Every value is mirrored into the URL. --}}
    <div class="mb-4 flex flex-wrap items-end gap-2" role="group" aria-label="Report filters">
        @if (app(Tenancy::class)->hasMultipleSites())
            <livewire:site-switcher :key="'reports-site'" />
        @endif

        <flux:select wire:model.live="rangeKey" class="min-w-40" icon="calendar" label="Period" label:sr-only>
            @foreach (DateRange::reportOptions() as $key => $label)
                <flux:select.option :value="$key">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($rangeKey === 'custom')
            <flux:input type="date" wire:model.live="fromDate" label="From" label:sr-only class="max-w-44" />
            <flux:input type="date" wire:model.live="toDate" label="To" label:sr-only class="max-w-44" />
        @endif

        <flux:select wire:model.live="compareKey" class="min-w-44" label="Compare with" label:sr-only>
            @foreach (DateRange::comparisonOptions() as $key => $label)
                <flux:select.option :value="$key">{{ $key === 'none' ? 'No comparison' : 'Compare: '.$label }}</flux:select.option>
            @endforeach
        </flux:select>

        @unless ($this->isShop())
            <flux:select wire:model.live="audience" class="min-w-48" label="Vehicles included" label:sr-only>
                @foreach (DateRange::audienceOptions() as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        @endunless
    </div>

    <x-tabs :tabs="$this->tabs" :current="$tab" model="tab" label="Report sections" class="mb-4" />

    <div role="tabpanel" aria-labelledby="tab-{{ $tab }}" wire:loading.class="opacity-60" wire:target="tab, rangeKey, compareKey, audience, fromDate, toDate">

    {{-- ─────────────── Summary ─────────────── --}}
    @if ($tab === 'summary')
        <div class="grid grid-cols-4 gap-4 max-xl:grid-cols-2 max-sm:grid-cols-1">
            @foreach ($this->summaryCards as $card)
                <x-kpi-card
                    :label="$card['label']"
                    :value="$card['value']"
                    :icon="$card['icon']"
                    :delta="$card['delta']"
                    :comparison="$card['comparison']"
                    :warning="$card['warning']"
                />
            @endforeach
        </div>

        @if ($this->dwell['sample'] > 0 && $this->lowStaySample)
            <x-notice class="mt-4" title="Limited stay data">
                Only {{ number_format($this->dwell['sample']) }} completed {{ Str::plural('visit', $this->dwell['sample']) }} had a matched exit in this period.
                Treat stay length with care; it is not compared with other periods until at least {{ (int) config('trafficflow.analytics.min_stay_sample') }} completed visits are available.
            </x-notice>
        @endif

        <x-panel-card
            class="mt-4"
            :title="$this->range->grain() === 'hour' ? 'Trend by hour' : ($this->range->grain() === 'week' ? 'Trend by week' : 'Trend by day')"
            :description="($this->comparison ? 'Selected period against '.strtolower(DateRange::comparisonOptions()[$compareKey]) : 'Selected period')
                .($this->excludeWet && $this->range->grain() === 'day' ? ' · wet days hidden' : '')
                .($this->excludeHolidays && $this->range->grain() === 'day' ? ' · holidays hidden' : '')"
        >
            <x-slot:actions>
                <flux:select wire:model.live="chartMetric" class="min-w-40" label="Metric" label:sr-only>
                    <flux:select.option value="visits">Visits</flux:select.option>
                    <flux:select.option value="unique">Unique vehicles</flux:select.option>
                    <flux:select.option value="exits">Exits</flux:select.option>
                    @if ($this->hasOccupancy)
                        <flux:select.option value="occupancy">Occupancy</flux:select.option>
                    @endif
                </flux:select>
            </x-slot:actions>

            @if ($this->comparison)
                <x-chart
                    name="reports-trend"
                    :labels="$this->trend['labels']"
                    :series="[
                        ['label' => 'Selected period', 'values' => $this->trend['current'], 'color' => 'accent'],
                        ['label' => 'Comparison', 'values' => $this->trend['previous'], 'color' => 'accentSoft'],
                    ]"
                    :annotations="$this->dayAnnotations"
                    :show-legend="true"
                    :height="240"
                    aria-label="Bar chart of the selected metric over time, compared with the comparison period"
                />
            @else
                <x-chart
                    name="reports-trend"
                    :labels="$this->trend['labels']"
                    :values="$this->trend['current']"
                    :annotations="$this->dayAnnotations"
                    :height="240"
                    aria-label="Bar chart of the selected metric over time"
                />
            @endif

            @if ($this->range->grain() === 'day')
                <details class="tf-disclosure mt-3 border-t border-line pt-1" @if ($excludeWet || $excludeHolidays) open @endif>
                    <summary>Weather and holidays{{ count($this->notableDays) ? ' ('.count($this->notableDays).')' : '' }}</summary>
                    <div class="pb-2">
                        @if (! empty($this->notableDays))
                            <ul class="mb-3 flex flex-wrap gap-1.5">
                                @foreach ($this->notableDays as $chip)
                                    <li class="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2.5 py-1 text-[12px] text-ink-2">
                                        <flux:icon :icon="$chip['kind'] === 'holiday' ? 'calendar-days' : 'cloud'" class="size-3.5" aria-hidden="true" />
                                        <span class="font-semibold tabular-nums text-ink">{{ $chip['label'] }}</span>
                                        <span>· {{ $chip['text'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mb-3 text-[13px] text-ink-2">No public holidays or wet days recorded in this period. Hover a bar to see that day's conditions.</p>
                        @endif

                        <div class="flex flex-wrap gap-x-5 gap-y-1">
                            <flux:checkbox wire:model.live="excludeWet" label="Hide wet days" />
                            <flux:checkbox wire:model.live="excludeHolidays" label="Hide public holidays" />
                        </div>
                        <p class="mt-1.5 text-[12px] text-ink-2">
                            Only changes this chart. The headline figures, other tabs and exports still count every day.
                            Wet means {{ Str::lower(implode(', ', DayContextAnalytics::WET_LABELS)) }}.
                        </p>
                    </div>
                </details>
            @endif
        </x-panel-card>

        <x-panel-card class="mt-4" title="Highlights" :description="$this->isShop() || $audience === 'shopper'
            ? 'Shopper traffic only · '.number_format($this->highlights['excluded']).' visits by vehicles recognised as staff or regulars are left out'
            : ($audience === 'staff' ? 'Staff and regular vehicles only' : 'All vehicles, including staff and regulars')">
            <dl class="grid grid-cols-3 gap-4 max-md:grid-cols-1">
                <div>
                    <dt class="text-[13px] text-ink-2">Busiest day</dt>
                    <dd class="mt-0.5 text-[16px] font-semibold text-ink">
                        @if ($this->highlights['busiest'])
                            {{ $this->highlights['busiest']['label'] }}
                            <span class="text-[13px] font-normal text-ink-2">· {{ number_format($this->highlights['busiest']['count']) }} {{ Str::plural('visit', $this->highlights['busiest']['count']) }}</span>
                        @else
                            <span class="text-ink-2">No visits yet</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[13px] text-ink-2">Peak hour</dt>
                    <dd class="mt-0.5 text-[16px] font-semibold text-ink">
                        @if ($this->highlights['peak'])
                            {{ $this->highlights['peak']['label'] }}–{{ sprintf('%02d:00', ($this->highlights['peak']['hour'] + 1) % 24) }}
                            <span class="text-[13px] font-normal text-ink-2">· {{ number_format($this->highlights['peak']['count']) }} {{ Str::plural('arrival', $this->highlights['peak']['count']) }} across the period</span>
                        @else
                            <span class="text-ink-2">No arrivals yet</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[13px] text-ink-2">Daily average</dt>
                    <dd class="mt-0.5 text-[16px] font-semibold text-ink">
                        {{ number_format($this->highlights['daily_average']) }}
                        <span class="text-[13px] font-normal text-ink-2">visits per day · {{ $this->daily->count() }} {{ Str::plural('day', $this->daily->count()) }}</span>
                    </dd>
                </div>
            </dl>
        </x-panel-card>

        <details class="tf-disclosure mt-4 rounded-tf border border-line bg-surface px-4 sm:px-5">
            <summary>Daily breakdown</summary>
            <div class="pb-4">
                @if ($this->daily->isEmpty())
                    <x-empty-state title="No days in this period" />
                @else
                    <x-data-table :headers="['Day', ['label' => 'Visits', 'align' => 'right']]">
                        @foreach ($this->dailyRows as $day)
                            <tr wire:key="day-{{ $day['date'] }}">
                                <td class="border-b border-line py-2">{{ Date::parse($day['date'])->format('D j M Y') }}</td>
                                <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($day['count']) }}</td>
                            </tr>
                        @endforeach
                    </x-data-table>

                    @php
                        $page = min(max(1, $dailyPage), $this->dailyPageCount);
                        $first = ($page - 1) * $this::DAILY_PAGE_SIZE + 1;
                        $last = min($this->daily->count(), $page * $this::DAILY_PAGE_SIZE);
                    @endphp
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-[13px] text-ink-2">
                        <span>Days {{ $first }}–{{ $last }} of {{ $this->daily->count() }}, newest first. Exports include every day.</span>
                        @if ($this->dailyPageCount > 1)
                            <span class="flex gap-2">
                                <flux:button wire:click="previousDailyPage" :disabled="$page <= 1" class="min-h-11" icon="chevron-left">Newer</flux:button>
                                <flux:button wire:click="nextDailyPage" :disabled="$page >= $this->dailyPageCount" class="min-h-11" icon:trailing="chevron-right">Older</flux:button>
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        </details>
    @endif

    {{-- ─────────────── Traffic patterns ─────────────── --}}
    @if ($tab === 'traffic')
        <div class="grid grid-cols-2 gap-4 max-lg:grid-cols-1">
            <x-panel-card title="Arrivals by hour of day" description="Total arrivals in each hour, added up across the period">
                <x-chart
                    name="reports-hourly"
                    :labels="$this->hourly->pluck('label')->all()"
                    :values="$this->hourly->pluck('count')->all()"
                    :height="220"
                    aria-label="Bar chart of arrivals by hour of day"
                />
            </x-panel-card>

            <x-panel-card title="Arrivals by weekday" description="Average arrivals per occurrence of each weekday">
                <x-chart
                    name="reports-weekday"
                    :labels="$this->weekday->pluck('label')->all()"
                    :values="$this->weekday->pluck('count')->all()"
                    :height="220"
                    aria-label="Bar chart of average arrivals by weekday"
                />
            </x-panel-card>
        </div>

        <x-panel-card class="mt-4" title="Busy times" description="Average arrivals for each weekday and hour. Hover a cell for its value.">
            <x-slot:actions>
                <span class="flex items-center gap-2 text-[12px] text-ink-2" aria-hidden="true">
                    <span class="size-3 rounded-sm bg-accent/20"></span> Quiet
                    <span class="size-3 rounded-sm bg-accent"></span> Busy
                </span>
            </x-slot:actions>
            <x-heatmap :rows="$this->heatmap" :max="$this->heatmapMax" />
        </x-panel-card>

        <x-panel-card class="mt-4" title="Entry points" description="Arrivals by entrance camera in this period">
            @if ($this->entryPoints->isEmpty())
                <x-empty-state title="No entrance-camera arrivals in this period" icon="map-pin" />
            @else
                @php $entryTotal = $this->entryPoints->sum('count') ?: 1; @endphp
                <x-data-table :headers="['Entry point', ['label' => 'Arrivals', 'align' => 'right'], ['label' => 'Share', 'align' => 'right']]">
                    @foreach ($this->entryPoints as $entry)
                        <tr wire:key="entry-{{ $entry['label'] }}">
                            <td class="border-b border-line py-2">{{ $entry['label'] }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($entry['count']) }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">{{ number_format($entry['count'] / $entryTotal * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        </x-panel-card>

        @if ($this->occupancySummary)
            <x-panel-card class="mt-4" title="Occupancy" :description="'Vehicles on site reconstructed from entries and exits, against '.number_format($this->occupancySummary['capacity']).' parking spaces'">
                <dl class="mb-4 grid grid-cols-4 gap-4 max-lg:grid-cols-2">
                    <div><dt class="text-[13px] text-ink-2">Average occupancy</dt><dd class="text-[18px] font-semibold tabular-nums">{{ number_format($this->occupancySummary['average'], 1) }}</dd></div>
                    <div><dt class="text-[13px] text-ink-2">Peak occupancy</dt><dd class="text-[18px] font-semibold tabular-nums">{{ number_format($this->occupancySummary['peak']) }}</dd></div>
                    <div><dt class="text-[13px] text-ink-2">Peak time</dt><dd class="text-[18px] font-semibold tabular-nums">{{ $this->occupancySummary['peak_at'] ? Date::parse($this->occupancySummary['peak_at'])->format('j M H:i') : '—' }}</dd></div>
                    <div><dt class="text-[13px] text-ink-2">Parking pressure</dt><dd class="text-[18px] font-semibold tabular-nums">{{ $this->occupancySummary['parking_pressure'] }}</dd><dd class="text-[12px] text-ink-2">above 80% · above 90%: {{ OccupancyAnalytics::formatDuration($this->occupancySummary['minutes_above_90']) }}</dd></div>
                </dl>
                <x-chart
                    name="reports-occupancy"
                    :labels="$this->occupancy->series($this->range)->pluck('label')->all()"
                    :values="$this->occupancy->series($this->range)->pluck('count')->all()"
                    :height="220"
                    aria-label="Bar chart of occupancy over time"
                />
            </x-panel-card>
        @endif

        <details class="tf-disclosure mt-4 rounded-tf border border-line bg-surface px-4 sm:px-5">
            <summary>Weather impact</summary>
            <div class="pb-4">
                @php $wx = $this->weatherImpact; @endphp
                @if ($wx === null)
                    <x-empty-state title="No weather data for this period">
                        Weather is recorded daily for sites with a location set. Add coordinates to the site to start collecting it.
                    </x-empty-state>
                @elseif (! $wx['has_enough_data'])
                    <p class="text-[13px] text-ink-2">
                        Not enough data for a reliable comparison yet
                        ({{ $wx['wet_days_count'] }} {{ Str::plural('wet day', $wx['wet_days_count']) }},
                        {{ $wx['dry_days_count'] }} {{ Str::plural('dry day', $wx['dry_days_count']) }}).
                        Try a longer period — 30 days or more usually has enough weather variation.
                    </p>
                @else
                    <dl class="grid grid-cols-3 gap-4 max-md:grid-cols-1">
                        <div>
                            <dt class="text-[13px] text-ink-2">Wet days</dt>
                            <dd class="text-[20px] font-semibold tabular-nums">{{ number_format($wx['wet_avg_visits']) }} <span class="text-[13px] font-normal text-ink-2">visits/day · {{ $wx['wet_days_count'] }} {{ Str::plural('day', $wx['wet_days_count']) }}</span></dd>
                        </div>
                        <div>
                            <dt class="text-[13px] text-ink-2">Dry days</dt>
                            <dd class="text-[20px] font-semibold tabular-nums">{{ number_format($wx['dry_avg_visits']) }} <span class="text-[13px] font-normal text-ink-2">visits/day · {{ $wx['dry_days_count'] }} {{ Str::plural('day', $wx['dry_days_count']) }}</span></dd>
                        </div>
                        <div>
                            <dt class="text-[13px] text-ink-2">Weekday-adjusted difference</dt>
                            <dd class="text-[20px] font-semibold tabular-nums">
                                {{ $wx['delta_percent'] === null ? '—' : ($wx['delta_percent'] > 0 ? '+' : '').number_format($wx['delta_percent'], 1).'%' }}
                            </dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-[12px] text-ink-2">
                        Wet days compared with dry days on the same weekday. An association only — it does not show that weather caused the difference.
                    </p>
                @endif
            </div>
        </details>
    @endif

    {{-- ─────────────── Visit behaviour ─────────────── --}}
    @if ($tab === 'behaviour')
        @php $return30 = $this->analytics->returnRate30Day($this->range); @endphp
        <div class="grid grid-cols-4 gap-4 max-xl:grid-cols-2 max-sm:grid-cols-1">
            <x-kpi-card label="First-time vehicles" :value="number_format($this->analytics->firstTimeVehicles($this->range))" icon="sparkles"
                comparison="Not seen before this period" />
            <x-kpi-card label="Returning vehicles" :value="$this->returning === null ? '—' : number_format($this->returning)" icon="arrow-path"
                :comparison="$this->returning === null ? 'Not enough history before this period yet' : 'Seen at least once before this period'" />
            <x-kpi-card label="Returning share" :value="$this->returnRate === null ? '—' : $this->returnRate.'%'" icon="chart-pie"
                :comparison="$this->returnRate === null
                    ? 'Not enough history yet'
                    : number_format($this->returning ?? 0).' of '.number_format($this->unique).' unique vehicles'.($return30 === null ? '' : ' · 30-day: '.$return30.'%')" />
            <x-kpi-card label="Typical stay" :value="$this->dwell['median'] === null ? '—' : $this->dwell['median'].' min'" icon="clock"
                :comparison="$this->dwell['sample'] === 0 ? 'No completed visits in this period' : 'Median of '.number_format($this->dwell['sample']).' completed '.Str::plural('visit', $this->dwell['sample'])"
                :warning="$this->dwell['sample'] > 0 && $this->lowStaySample ? 'Low sample — interpret with care.' : null" />
        </div>

        <x-notice tone="info" class="mt-4" title="How returning is defined">
            A returning vehicle is a registration seen in this period that was also recorded at any time before the period started, within the data retained for this site.
            The 30-day figure only looks back 30 days. Vehicles are identified by number plate, so a returning vehicle is not necessarily the same person.
        </x-notice>

        <div class="mt-4 grid grid-cols-2 gap-4 max-lg:grid-cols-1">
            <x-panel-card title="Repeat visit frequency" description="Unique vehicles by how many times they visited in this period">
                <x-chart-table label="Repeat frequency view">
                    <x-slot:chart>
                        <x-chart
                            name="reports-frequency"
                            :labels="$this->frequency->pluck('label')->all()"
                            :values="$this->frequency->pluck('count')->all()"
                            :height="220"
                            aria-label="Bar chart of unique vehicles by number of visits"
                        />
                    </x-slot:chart>
                    <x-slot:table>
                        <x-data-table :headers="['Visits in period', ['label' => 'Vehicles', 'align' => 'right'], ['label' => 'Share', 'align' => 'right']]">
                            @foreach ($this->frequency as $bucket)
                                <tr wire:key="freq-{{ $bucket['label'] }}">
                                    <td class="border-b border-line py-2">{{ $bucket['label'] }}</td>
                                    <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($bucket['count']) }}</td>
                                    <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">{{ $bucket['percent'] }}%</td>
                                </tr>
                            @endforeach
                        </x-data-table>
                    </x-slot:table>
                </x-chart-table>
            </x-panel-card>

            <x-panel-card title="Length of stay" :description="$this->dwell['sample'] === 0
                ? 'Completed visits with a matched exit'
                : 'Based on '.number_format($this->dwell['sample']).' completed '.Str::plural('visit', $this->dwell['sample']).' with a matched exit'">
                @if ($this->dwell['sample'] === 0)
                    <x-empty-state title="No completed visits in this period" icon="clock">
                        Stay length needs an entry and a matched exit for the same vehicle. See System health for how many exits were matched.
                    </x-empty-state>
                @else
                    <x-chart-table label="Length of stay view">
                        <x-slot:chart>
                            <x-chart
                                name="reports-stay"
                                :labels="$this->stayDistribution->pluck('label')->all()"
                                :values="$this->stayDistribution->pluck('count')->all()"
                                :height="220"
                                aria-label="Bar chart of completed visits by length of stay"
                            />
                        </x-slot:chart>
                        <x-slot:table>
                            <x-data-table :headers="['Length of stay', ['label' => 'Completed visits', 'align' => 'right'], ['label' => 'Share', 'align' => 'right']]">
                                @foreach ($this->stayDistribution as $bucket)
                                    <tr wire:key="stay-{{ $bucket['label'] }}">
                                        <td class="border-b border-line py-2">{{ $bucket['label'] }}</td>
                                        <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($bucket['count']) }}</td>
                                        <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">{{ $bucket['percent'] }}%</td>
                                    </tr>
                                @endforeach
                            </x-data-table>
                        </x-slot:table>
                    </x-chart-table>
                    @if ($this->lowStaySample)
                        <p class="mt-2 text-[12px] text-ink-2">Low sample: a handful of visits can swing these shares a lot.</p>
                    @endif
                @endif
            </x-panel-card>
        </div>
    @endif

    {{-- ─────────────── Security ─────────────── --}}
    @if ($tab === 'security' && $this->canSeeOps)
        <div class="grid grid-cols-4 gap-4 max-xl:grid-cols-2 max-sm:grid-cols-1">
            <x-kpi-card label="Watchlist matches" :value="number_format($this->securitySummary['watchlist_hits'])" icon="bell-alert" comparison="Watchlisted plates detected" />
            <x-kpi-card label="Long-stay alerts" :value="number_format($this->securitySummary['long_dwell'])" icon="clock" comparison="Over the site's stay threshold" />
            <x-kpi-card label="Unusual-hour alerts" :value="number_format($this->securitySummary['odd_hour'])" icon="moon" comparison="Repeated arrivals at night" />
            <x-kpi-card label="Repeated-entry alerts" :value="number_format($this->securitySummary['multi_entry'])" icon="arrows-right-left" comparison="Same plate entering several times a day" />
        </div>

        <x-panel-card class="mt-4" title="Incident history" description="Alerts raised by the configured security rules in this period">
            <x-slot:actions>
                <a href="{{ route('security') }}" wire:navigate class="inline-flex min-h-11 items-center text-[13px] font-medium text-accent hover:underline">Live alerts</a>
            </x-slot:actions>

            @if ($this->incidentTotal === 0)
                <x-empty-state title="No incidents recorded for this period" icon="shield-check">
                    Alerts appear here when a configured rule is triggered. Rules and thresholds are set per site in Settings.
                </x-empty-state>
            @else
                @php $security = app(SecurityAnalytics::class); @endphp
                <div class="grid grid-cols-[2fr_1fr] gap-6 max-lg:grid-cols-1">
                    <x-chart
                        name="reports-incidents"
                        :labels="$security->incidentsByDay($this->range)->pluck('label')->all()"
                        :values="$security->incidentsByDay($this->range)->pluck('count')->all()"
                        :height="220"
                        aria-label="Bar chart of security incidents by day"
                    />
                    <x-data-table :headers="['Rule', ['label' => 'Alerts', 'align' => 'right']]">
                        @foreach ($security->incidentsByType($this->range) as $row)
                            <tr wire:key="incident-{{ $row['label'] }}">
                                <td class="border-b border-line py-2">{{ $row['label'] }}</td>
                                <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($row['count']) }}</td>
                            </tr>
                        @endforeach
                    </x-data-table>
                </div>
            @endif
        </x-panel-card>

        <p class="mt-3 text-[13px] text-ink-2">
            Entries without a matching exit are a camera-coverage question rather than a security incident —
            <button type="button" wire:click="$set('tab', 'health')" class="inline-flex min-h-11 items-center font-medium text-accent hover:underline">see System health</button>.
        </p>
    @endif

    {{-- ─────────────── System health ─────────────── --}}
    @if ($tab === 'health' && $this->canSeeOps)
        @php
            $q = $this->quality;
            $excluded = $q['reads'] - $q['pairable_reads'];
        @endphp

        @if ($q['pairing_quality'] !== null && $q['orphan_entries'] > $q['paired_visits'])
            <x-notice class="mb-4" title="Entry and exit matching needs attention">
                Most entries in this period never matched an exit ({{ number_format($q['eligible_exits']) }} eligible exit {{ Str::plural('read', $q['eligible_exits']) }} against {{ number_format($q['eligible_entries']) }} entry reads).
                This usually means an exit camera is missing, mis-aimed or set to the wrong direction. Stay length and the live on-site count are less reliable until it is fixed.
            </x-notice>
        @endif

        <div class="grid grid-cols-4 gap-4 max-xl:grid-cols-2 max-sm:grid-cols-1">
            <x-kpi-card label="Plate reads received" :value="number_format($q['reads'])" icon="inbox-arrow-down" comparison="Every read the cameras sent, before any filtering" />
            <x-kpi-card label="Eligible for matching" :value="number_format($q['pairable_reads'])" icon="funnel"
                :comparison="number_format($excluded).' excluded as duplicates, unreadable or without a direction'" />
            <x-kpi-card label="Matched visits" :value="number_format($q['paired_visits'])" icon="link"
                :comparison="$q['pairing_quality'] === null ? 'No eligible reads yet' : number_format($q['paired_visits'] * 2).' of '.number_format($q['pairable_reads']).' eligible reads paired ('.$q['pairing_quality'].'%)'" />
            <x-kpi-card label="Cameras reachable now" :value="$q['cameras_total'] === 0 ? '—' : number_format($q['cameras_total'] - $q['cameras_offline']).' of '.number_format($q['cameras_total'])" icon="video-camera"
                comparison="Live check. Uptime history over the period is not recorded." />
        </div>

        <div class="mt-4 grid grid-cols-2 gap-4 max-lg:grid-cols-1">
            <x-panel-card title="Matching diagnostics" description="How entry and exit reads became visits in this period">
                <x-data-table :headers="['Measure', ['label' => 'Count', 'align' => 'right']]">
                    @foreach ([
                        ['Entry reads received', $q['entries']],
                        ['Exit reads received', $q['exits']],
                        ['Eligible entry reads', $q['eligible_entries']],
                        ['Eligible exit reads', $q['eligible_exits']],
                        ['Matched visits (entry + exit)', $q['paired_visits']],
                        ['Entries without matching exits', $q['orphan_entries']],
                        ['Entries still waiting for an exit', $q['open_visits']],
                        ['Exits without matching entries', $q['orphan_exits']],
                        ['Cameras offline now', $q['cameras_offline']],
                    ] as [$label, $count])
                        <tr>
                            <td class="border-b border-line py-2">{{ $label }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($count) }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-panel-card>

            <x-panel-card title="Excluded reads" :description="number_format($excluded).' of '.number_format($q['reads']).' received reads were not used for matching'">
                <x-data-table :headers="['Reason', ['label' => 'Reads', 'align' => 'right']]">
                    <tr>
                        <td class="border-b border-line py-2">Duplicate photo of a vehicle already counted</td>
                        <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($q['excluded_duplicates']) }}</td>
                    </tr>
                    <tr>
                        <td class="border-b border-line py-2">Plate could not be read</td>
                        <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($q['excluded_unreadable']) }}</td>
                    </tr>
                    <tr>
                        <td class="border-b border-line py-2">Camera sent no entry/exit direction</td>
                        <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($q['excluded_no_direction']) }}</td>
                    </tr>
                </x-data-table>
            </x-panel-card>
        </div>

        <details class="tf-disclosure mt-4 rounded-tf border border-line bg-surface px-4 sm:px-5">
            <summary>How these numbers are calculated</summary>
            <ul class="list-disc space-y-1.5 pb-4 pl-5 text-[13px] leading-snug text-ink-2">
                <li><strong class="text-ink">Received reads</strong> counts every plate read captured in the period. <strong class="text-ink">Eligible reads</strong> drops duplicates, unreadable plates and reads with no direction; each excluded read is counted once, under the first reason that applies.</li>
                <li><strong class="text-ink">Matched share</strong> is two reads per matched visit (one entry, one exit) divided by eligible reads. It can never exceed twice the number of eligible exit reads, so a site with few exit reads will show a low share even if every exit was matched.</li>
                <li><strong class="text-ink">Entries without matching exits</strong> are visits that waited longer than {{ (int) config('trafficflow.orphan_after_hours') }} hours without an exit and were closed as unmatched. Entries still within that window are listed separately as waiting.</li>
                <li><strong class="text-ink">Exits without matching entries</strong> are eligible exit reads that no visit claimed — typically a vehicle whose entry was missed or misread.</li>
                <li>Reads and visits are different units: one visit can absorb a re-read at the gate, so these rows are not expected to add up to the received total. Unlike the other tabs, this tab counts every vehicle, including staff and regulars.</li>
            </ul>
        </details>
    @endif
    </div>
</div>
