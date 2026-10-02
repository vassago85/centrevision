<?php

use App\Enums\PlateDirection;
use App\Enums\WatchlistKind;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Visit;
use App\Support\Analytics\DateRange;
use App\Support\Analytics\SecurityAnalytics;
use App\Support\Analytics\TrafficAnalytics;
use App\Support\PlateNumber;
use App\Support\Tenancy;
use App\Support\Weather\CurrentWeather;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    /**
     * Dashboard defaults to Today: it is the "what is happening now" screen,
     * not a mini-Reports. Longer historical windows live under Reports so
     * the two surfaces don't feel like duplicates.
     */
    #[Url(as: 'range', keep: true)]
    public string $rangeKey = 'today';

    /**
     * Optional camera filter for the plate-level "Recent activity" card.
     * Aggregate cards and the chart stay site-wide.
     */
    #[Url(as: 'camera', keep: true)]
    public ?int $cameraId = null;

    /**
     * Platform admins have no site of their own to show, so send them to the
     * cross-tenant view they actually landed here looking for.
     */
    public function mount(): void
    {
        if (auth()->user()->isPlatformAdmin()) {
            $this->redirectRoute('platform.overview', navigate: true);
        }

        if (! array_key_exists($this->rangeKey, DateRange::options())) {
            $this->rangeKey = 'today';
        }

        $this->normaliseCameraId();
    }

    public function updatedCameraId(): void
    {
        $this->normaliseCameraId();
    }

    /**
     * Active cameras of the current site, for the Recent activity filter.
     * Empty for shops (no plate-level UI) and for the "all sites" view.
     *
     * @return Collection<int, Camera>
     */
    #[Computed]
    public function cameras(): Collection
    {
        $site = app(Tenancy::class)->currentSite();

        if ($site === null || ! $this->canSeePlates()) {
            return collect();
        }

        return Camera::query()
            ->where('site_id', $site->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function hasMultipleCameras(): bool
    {
        return $this->cameras->count() > 1;
    }

    protected function normaliseCameraId(): void
    {
        if ($this->cameraId === null) {
            return;
        }

        if (! $this->cameras->contains('id', $this->cameraId)) {
            $this->cameraId = null;
        }
    }

    #[Computed]
    public function range(): DateRange
    {
        return DateRange::make($this->rangeKey);
    }

    /**
     * The previous period cut to the same elapsed time, so Today at 11:04 is
     * compared with yesterday up to 11:04, never with all of yesterday.
     */
    #[Computed]
    public function comparisonRange(): DateRange
    {
        return $this->range->elapsedComparisonRange('previous');
    }

    #[Computed]
    public function comparisonCaption(): string
    {
        if (! $this->range->isInProgress()) {
            return $this->isToday ? 'vs yesterday' : 'vs previous 7 days';
        }

        return $this->isToday
            ? 'vs yesterday to '.now()->format('H:i')
            : 'vs previous 7 days to the same point';
    }

    #[Computed]
    public function analytics(): TrafficAnalytics
    {
        return app(TrafficAnalytics::class);
    }

    #[Computed]
    public function heading(): string
    {
        $tenancy = app(Tenancy::class);

        return $tenancy->currentSite()?->name
            ?? ($tenancy->isShop() ? $tenancy->organization()?->name : 'Dashboard');
    }

    #[Computed]
    public function isToday(): bool
    {
        return $this->range->key === 'today';
    }

    /**
     * Today's numbers move minute by minute, so they poll tightly; the 7-day
     * view still carries the live on-site count but its bars barely move.
     * The browser suspends wire:poll when the tab is backgrounded.
     */
    #[Computed]
    public function pollInterval(): string
    {
        return $this->rangeKey === 'today' ? '15s' : '30s';
    }

    /**
     * Without an exit-capable camera there is no honest on-site count or
     * stay length, so those cards explain what is missing instead.
     */
    #[Computed]
    public function hasExitTracking(): bool
    {
        return $this->analytics()->hasExitTracking();
    }

    /**
     * @return array<int, array{label: string, value: string, icon: string, delta: string|null, comparison: string|null, warning: string|null}>
     */
    #[Computed]
    public function cards(): array
    {
        $range = $this->range;
        $previous = $this->comparisonRange;
        $a = $this->analytics();

        $visits = $a->totalVisits($range);
        $unique = $a->uniqueVehicles($range);

        return [
            $this->onSiteCard(),
            [
                'label' => $this->isToday ? 'Visits today' : 'Visits',
                'value' => number_format($visits),
                'icon' => 'arrow-right-end-on-rectangle',
                'delta' => $this->delta($visits, $a->totalVisits($previous)),
                'comparison' => $this->comparisonCaption.' · every arrival counts, including repeat trips',
                'warning' => null,
            ],
            [
                'label' => 'Unique vehicles',
                'value' => number_format($unique),
                'icon' => 'truck',
                'delta' => $this->delta($unique, $a->uniqueVehicles($previous)),
                'comparison' => $this->comparisonCaption.' · distinct registrations detected',
                'warning' => null,
            ],
            $this->stayCard(),
        ];
    }

    /**
     * Live count, independent of the period picker: open shopper visits
     * right now. Flagged as unreliable when recent visits mostly time out
     * instead of ending on a matched exit.
     *
     * @return array{label: string, value: string, icon: string, delta: string|null, comparison: string|null, warning: string|null}
     */
    protected function onSiteCard(): array
    {
        $card = ['label' => 'Vehicles on site', 'icon' => 'map-pin', 'delta' => null, 'warning' => null];

        if (! $this->hasExitTracking) {
            return [
                ...$card,
                'value' => '—',
                'comparison' => 'Needs an exit camera — entry-only sites cannot tell when a vehicle leaves.',
            ];
        }

        $a = $this->analytics();
        $onSite = $a->currentlyOnSite();
        $capacity = app(Tenancy::class)->currentSite()?->parkingCapacity();
        $occupancy = $a->occupancyPercent();

        $comparison = 'Estimate, live now · not affected by the period';

        if ($occupancy !== null && $capacity !== null) {
            $comparison = number_format($onSite).' of '.number_format($capacity).' spaces ('.rtrim(rtrim(number_format($occupancy, 1), '0'), '.').'%) · live estimate';
        }

        $days = (int) config('trafficflow.analytics.on_site_exit_match_days', 7);
        $exitMatch = $a->exitMatchRate($days);
        $minimum = (float) config('trafficflow.analytics.on_site_min_exit_match_percent', 50);

        return [
            ...$card,
            'value' => number_format($onSite),
            'comparison' => $comparison,
            'warning' => $exitMatch['percent'] !== null && $exitMatch['percent'] < $minimum
                ? 'Only '.rtrim(rtrim(number_format($exitMatch['percent'], 1), '0'), '.').'% of visits in the last '.$days.' days had a matched exit, so some of these vehicles may have left.'
                : null,
        ];
    }

    /**
     * Typical stay is the median length of completed, matched visits. Small
     * samples are labelled and never compared with the previous period.
     *
     * @return array{label: string, value: string, icon: string, delta: string|null, comparison: string|null, warning: string|null}
     */
    protected function stayCard(): array
    {
        $card = ['label' => 'Typical stay', 'icon' => 'clock', 'delta' => null, 'warning' => null];

        if (! $this->hasExitTracking) {
            return [...$card, 'value' => '—', 'comparison' => 'Needs an exit camera to measure how long vehicles stay.'];
        }

        $dwell = $this->analytics()->dwellSummary($this->range);

        if ($dwell['sample'] === 0 || $dwell['median'] === null) {
            return [...$card, 'value' => '—', 'comparison' => 'No completed visits '.($this->isToday ? 'today' : 'in this period').' yet.'];
        }

        $basis = 'Median of '.number_format($dwell['sample']).' completed '.Str::plural('visit', $dwell['sample']);

        if (TrafficAnalytics::isLowStaySample($dwell['sample'])) {
            return [
                ...$card,
                'value' => $dwell['median'].' min',
                'comparison' => $basis,
                'warning' => 'Low sample — not compared with the previous period.',
            ];
        }

        $previous = $this->analytics()->dwellSummary($this->comparisonRange);

        return [
            ...$card,
            'value' => $dwell['median'].' min',
            'delta' => TrafficAnalytics::isLowStaySample($previous['sample']) ? null : $this->delta($dwell['median'], $previous['median']),
            'comparison' => $basis.' · average '.$dwell['average'].' min',
        ];
    }

    protected function delta(int|float|null $current, int|float|null $previous): ?string
    {
        $compare = $this->analytics()->comparison($current, $previous);

        return $compare['label'] === 'No prior data' ? null : $compare['label'];
    }

    /**
     * Hourly arrivals for the chart. Today stops at the current hour for both
     * series, so future hours are absent rather than drawn as zeroes. The
     * 7-day view sums each hour of day across the period.
     *
     * @return array{labels: array<int, string>, current: array<int, int>, previous: array<int, int>, currentLabel: string, previousLabel: string}
     */
    #[Computed]
    public function hourly(): array
    {
        $a = $this->analytics;

        if ($this->isToday) {
            $now = now();
            $today = $a->visitsByHourOnDay($now, $now);
            $yesterday = $a->visitsByHourOnDay($now->copy()->subDay(), $now);

            return [
                'labels' => $today->pluck('label')->all(),
                'current' => $today->pluck('count')->all(),
                'previous' => $yesterday->pluck('count')->all(),
                'currentLabel' => 'Today',
                'previousLabel' => 'Yesterday',
            ];
        }

        $current = $a->visitsByHour($this->range);
        $previous = $a->visitsByHour($this->comparisonRange);

        return [
            'labels' => $current->pluck('label')->all(),
            'current' => $current->pluck('count')->all(),
            'previous' => $previous->pluck('count')->all(),
            'currentLabel' => 'Last 7 days',
            'previousLabel' => 'Previous 7 days',
        ];
    }

    /**
     * @return array{hour: int, label: string, count: int}|null
     */
    #[Computed]
    public function peakHour(): ?array
    {
        return $this->analytics->peakHour($this->range);
    }

    /**
     * @return Collection<int, array{label: string, count: int, percent: float}>
     */
    #[Computed]
    public function entryPoints(): Collection
    {
        return $this->analytics->topEntryPoints($this->range);
    }

    /**
     * Live "now" weather for the header. Cached per site by the service, so
     * the poll cycle doesn't re-hit Open-Meteo on every render.
     *
     * @return array{temp_c: float|null, weather_code: int|null, weather_label: string|null}|null
     */
    #[Computed]
    public function headerWeather(): ?array
    {
        return app(CurrentWeather::class)->forCurrentView();
    }

    /**
     * Watchlist- and security-related counts for the alert panel and bell.
     *
     * Counts events that happened *after* the user last visited /security
     * (their `alerts_last_seen_at`), so opening the security page clears the
     * badge until new events arrive. First-time visitors — with no
     * acknowledgement on file yet — fall back to a 24h window so the bell is
     * neither perpetually silent nor screaming with history.
     *
     * @return array{watchlist: int, blacklist: int, other: int, total: int}
     */
    #[Computed]
    public function alertCounts(): array
    {
        $security = app(SecurityAnalytics::class);
        $siteIds = app(Tenancy::class)->scopeSiteIds();

        $windowStart = auth()->user()?->alerts_last_seen_at ?? now()->subDay();

        $hitsByKind = PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->join('cameras', 'cameras.id', '=', 'plate_events.camera_id')
            ->join('watchlist_plates', function ($join) {
                $join->on('plate_events.plate_number', '=', 'watchlist_plates.plate_number')
                    ->on('cameras.site_id', '=', 'watchlist_plates.site_id');
            })
            ->whereIn('watchlist_plates.site_id', $siteIds)
            ->where('plate_events.captured_at', '>', $windowStart)
            ->toBase()
            ->selectRaw('watchlist_plates.kind, COUNT(*) as hits')
            ->groupBy('watchlist_plates.kind')
            ->pluck('hits', 'kind');

        $watchlistHits = (int) ($hitsByKind[WatchlistKind::Watch->value] ?? 0)
            + (int) ($hitsByKind[WatchlistKind::Vip->value] ?? 0);
        $blacklistHits = (int) ($hitsByKind[WatchlistKind::Block->value] ?? 0);

        // "Other" = anomalies from the behavioural rules on the Security page,
        // using the site's dwell threshold when one is pinned.
        $dwellHours = (int) (app(Tenancy::class)->currentSite()?->settings['dwell_alert_hours']
            ?? config('trafficflow.security.default_dwell_alert_hours', 4));

        // Only count breaches new since the user last checked.
        $newOverThreshold = $security->overThreshold($dwellHours)
            ->filter(fn ($visit) => $visit->entered_at->gt($windowStart->copy()->subHours($dwellHours)))
            ->count();

        $newMultiEntry = $security->multipleEntriesToday()
            ->filter(function (array $row) use ($windowStart) {
                $latest = end($row['times']);

                return is_string($latest) && $latest !== ''
                    && Date::createFromFormat('Y-m-d H:i', now()->toDateString().' '.$latest)
                        ?->gt($windowStart);
            })
            ->count();

        $other = $newOverThreshold + $newMultiEntry;

        return [
            'watchlist' => $watchlistHits,
            'blacklist' => $blacklistHits,
            'other' => $other,
            'total' => $watchlistHits + $blacklistHits + $other,
        ];
    }

    #[Computed]
    public function alertWindowCaption(): string
    {
        return auth()->user()?->alerts_last_seen_at === null
            ? 'New in the last 24 hours'
            : 'New since you last opened Security';
    }

    /**
     * @return Collection<int, object>
     */
    #[Computed]
    public function recentWatchlistHits(): Collection
    {
        $siteIds = app(Tenancy::class)->scopeSiteIds();

        return PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->join('cameras', 'cameras.id', '=', 'plate_events.camera_id')
            ->join('watchlist_plates', function ($join) {
                $join->on('plate_events.plate_number', '=', 'watchlist_plates.plate_number')
                    ->on('cameras.site_id', '=', 'watchlist_plates.site_id');
            })
            ->whereIn('watchlist_plates.site_id', $siteIds)
            ->where('plate_events.captured_at', '>=', now()->subDays(2))
            ->toBase()
            ->selectRaw('plate_events.plate_number, plate_events.captured_at, cameras.name AS camera_name, cameras.id AS camera_id, watchlist_plates.kind')
            ->orderByDesc('plate_events.captured_at')
            ->limit(4)
            ->get();
    }

    #[Computed]
    public function canSeePlates(): bool
    {
        return auth()->user()->can('viewAny', Visit::class);
    }

    /**
     * The last five plate detections, entries and exits both, from
     * plate_events so a re-entry shows as its own row. Owner and security
     * only: shops see aggregates, never plates.
     *
     * @return Collection<int, PlateEvent>
     */
    #[Computed]
    public function latestEntries(): Collection
    {
        if (! $this->canSeePlates()) {
            return collect();
        }

        return $this->analytics()
            ->recentDetections(5, $this->cameraId)
            ->each(fn (PlateEvent $e) => $e->makeVisible('plate_number'));
    }

    /**
     * Security and watchlist content uses the same "can see individual
     * vehicles" gate as plates; shops get the aggregate view either way.
     */
    #[Computed]
    public function canSeeSecurity(): bool
    {
        return $this->canSeePlates();
    }

}; ?>

<div wire:poll.{{ $this->pollInterval }}>
    <x-dashboard-header
        :title="$this->heading"
        :subtitle="$this->isToday ? 'Your centre at a glance · today' : 'Your centre at a glance · last 7 days'"
        :alert-count="$this->canSeeSecurity ? $this->alertCounts['total'] : 0"
        :show-bell="$this->canSeeSecurity"
        :weather="$this->headerWeather"
        live
    >
        <x-slot:actions>
            @if (app(Tenancy::class)->hasMultipleSites())
                <livewire:site-switcher :key="'dashboard-site'" />
            @endif
            <flux:select wire:model.live="rangeKey" class="min-w-40" icon="calendar" label="Period" label:sr-only>
                @foreach (DateRange::options() as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-dashboard-header>

    <div class="grid grid-cols-4 gap-4 max-xl:grid-cols-2 max-sm:grid-cols-1">
        @foreach ($this->cards as $card)
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

    <x-panel-card
        class="mt-4"
        :title="$this->isToday ? 'Arrivals by hour' : 'Arrivals by hour of day'"
        :description="$this->isToday
            ? 'Today and yesterday, through '.now()->format('H:i').'. Later hours appear as they happen.'
            : 'Total arrivals in each hour across the last 7 days, against the previous 7 days to the same point.'"
    >
        <x-slot:actions>
            <span class="text-[13px] text-ink-2">
                @if ($this->peakHour)
                    Busiest: <span class="font-semibold text-ink tabular-nums">{{ $this->peakHour['label'] }}</span>
                    · {{ number_format($this->peakHour['count']) }} {{ Str::plural('arrival', $this->peakHour['count']) }}
                @else
                    No arrivals yet
                @endif
            </span>
        </x-slot:actions>

        <x-chart
            name="dashboard-hourly"
            :labels="$this->hourly['labels']"
            :series="[
                ['label' => $this->hourly['currentLabel'], 'values' => $this->hourly['current'], 'color' => 'accent'],
                ['label' => $this->hourly['previousLabel'], 'values' => $this->hourly['previous'], 'color' => 'accentSoft'],
            ]"
            :show-legend="true"
            :height="240"
            :aria-label="'Grouped bar chart of arrivals per hour: '.$this->hourly['currentLabel'].' compared with '.$this->hourly['previousLabel']"
        />
    </x-panel-card>

    <div @class([
        'mt-4 grid gap-4',
        'grid-cols-2 max-lg:grid-cols-1' => $this->canSeeSecurity,
    ])>
        <x-panel-card title="Entry points" description="Share of arrivals by entrance camera in this period">
            @if ($this->canSeePlates)
                <x-slot:actions>
                    <a href="{{ route('cameras') }}" wire:navigate class="inline-flex min-h-11 items-center text-[13px] font-medium text-accent hover:underline">Cameras</a>
                </x-slot:actions>
            @endif

            @if ($this->entryPoints->isEmpty())
                <x-empty-state title="No arrivals recorded yet" icon="map-pin">
                    Entrance-camera arrivals for this period will be listed here.
                </x-empty-state>
            @else
                @php $entryTotal = $this->entryPoints->sum('count') ?: 1; @endphp
                <x-data-table :headers="['Entry point', ['label' => 'Arrivals', 'align' => 'right'], ['label' => 'Share', 'align' => 'right']]">
                    @foreach ($this->entryPoints as $entry)
                        <tr wire:key="entry-{{ $entry['label'] }}">
                            <td class="border-b border-line py-2.5 text-ink">{{ $entry['label'] }}</td>
                            <td class="border-b border-line py-2.5 text-right font-semibold tabular-nums">{{ number_format($entry['count']) }}</td>
                            <td class="border-b border-line py-2.5 text-right tabular-nums text-ink-2">{{ number_format($entry['count'] / $entryTotal * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        </x-panel-card>

        @if ($this->canSeeSecurity)
            <x-panel-card title="Security & watchlist" :description="$this->alertWindowCaption">
                <x-slot:actions>
                    <a href="{{ route('security') }}" wire:navigate class="inline-flex min-h-11 items-center text-[13px] font-medium text-accent hover:underline">Security</a>
                </x-slot:actions>

                <ul class="grid grid-cols-3 gap-2 max-sm:grid-cols-1">
                    @foreach ([
                        ['label' => 'Watchlist alerts', 'route' => 'watchlist', 'count' => $this->alertCounts['watchlist'], 'help' => 'Raised for Watch and VIP plates', 'danger' => false],
                        ['label' => 'Blocked-plate alerts', 'route' => 'watchlist', 'count' => $this->alertCounts['blacklist'], 'help' => 'Raised for plates labelled Blocked', 'danger' => true],
                        ['label' => 'Other alerts', 'route' => 'security', 'count' => $this->alertCounts['other'], 'help' => 'Long stays, odd hours, repeated entries', 'danger' => false],
                    ] as $alert)
                        <li>
                            <a
                                href="{{ route($alert['route']) }}"
                                wire:navigate
                                class="flex min-h-11 flex-col rounded-lg border border-line px-3 py-2 transition-colors hover:border-accent/40 hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                            >
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="text-[13px] font-medium text-ink-2">{{ $alert['label'] }}</span>
                                    @php $loud = $alert['danger'] && $alert['count'] > 0; @endphp
                                    <span @class(['text-[20px] font-semibold tabular-nums', 'text-danger' => $loud, 'text-ink' => ! $loud])>{{ $alert['count'] }}</span>
                                </span>
                                <span class="text-[12px] text-ink-2">{{ $alert['help'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <h3 class="mt-4 mb-1 text-[13px] font-semibold text-ink">Recent watchlist matches <span class="font-normal text-ink-muted">· camera reads, last 48 hours</span></h3>
                @if ($this->recentWatchlistHits->isEmpty())
                    <p class="text-[13px] text-ink-2">No watchlist plates read in the last 48 hours.</p>
                @else
                    <ul class="divide-y divide-line text-[13px]">
                        @foreach ($this->recentWatchlistHits as $hit)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <span class="min-w-0">
                                    <span @class(['font-mono font-semibold', 'text-danger' => $hit->kind === WatchlistKind::Block->value, 'text-ink' => $hit->kind !== WatchlistKind::Block->value])>
                                        {{ PlateNumber::forDisplay($hit->plate_number) }}
                                    </span>
                                    <span class="text-ink-2"> · {{ $hit->camera_name }}</span>
                                </span>
                                <span class="shrink-0 tabular-nums text-ink-2">{{ Date::parse($hit->captured_at)->format('D H:i') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-panel-card>
        @endif
    </div>

    @if ($this->canSeePlates)
        <x-panel-card class="mt-4" title="Recent activity" description="Latest 5 camera detections, entries and exits">
            <x-slot:actions>
                @if ($this->hasMultipleCameras)
                    <flux:select wire:model.live="cameraId" class="min-w-40" label="Camera" label:sr-only>
                        <flux:select.option :value="null">All cameras</flux:select.option>
                        @foreach ($this->cameras as $camera)
                            <flux:select.option :value="$camera->id">{{ $camera->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <a href="{{ route('activity') }}" wire:navigate class="inline-flex min-h-11 items-center text-[13px] font-medium text-accent hover:underline">View all activity</a>
            </x-slot:actions>

            @if ($this->latestEntries->isEmpty())
                <x-empty-state :title="$this->cameraId ? 'No detections for this camera yet' : 'No detections yet'" icon="truck" />
            @else
                <div class="relative overflow-x-auto">
                    <x-data-table :headers="['Vehicle', 'Time', 'Camera', 'Direction', ['label' => $this->hasExitTracking ? 'Currently' : 'Confidence', 'align' => 'right']]">
                        @foreach ($this->latestEntries as $entry)
                            @php $isIn = $entry->direction === PlateDirection::In; @endphp
                            <tr wire:key="latest-entry-{{ $entry->id }}">
                                <td class="border-b border-line py-2.5 font-mono font-semibold whitespace-nowrap text-ink">{{ PlateNumber::forDisplay($entry->plate_number) }}</td>
                                <td class="border-b border-line py-2.5 whitespace-nowrap tabular-nums text-ink-2">{{ $entry->captured_at->isToday() ? $entry->captured_at->format('H:i') : $entry->captured_at->format('D H:i') }}</td>
                                <td class="border-b border-line py-2.5 text-ink-2">{{ $entry->camera?->name ?? '—' }}</td>
                                <td class="border-b border-line py-2.5">
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[12px] font-semibold',
                                        'bg-accent-soft text-accent' => $isIn,
                                        'bg-surface-2 text-ink-2' => ! $isIn,
                                    ])>
                                        <flux:icon :icon="$isIn ? 'arrow-down-right' : 'arrow-up-left'" class="size-3" aria-hidden="true" />
                                        {{ $isIn ? 'Entry' : 'Exit' }}
                                    </span>
                                </td>
                                <td class="border-b border-line py-2.5 text-right">
                                    @if ($this->hasExitTracking)
                                        @if ($entry->getAttribute('on_site_now'))
                                            <span class="rounded-full bg-accent-soft px-2 py-0.5 text-[12px] font-semibold text-accent">On site</span>
                                        @else
                                            <span class="text-ink-2" aria-label="Not on site">—</span>
                                        @endif
                                    @else
                                        @php $conf = $entry->confidence === null ? null : (int) round($entry->confidence * 100); @endphp
                                        <span class="tabular-nums text-ink-2">{{ $conf === null ? '—' : $conf.'%' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-data-table>
                </div>
            @endif
        </x-panel-card>
    @endif
</div>
