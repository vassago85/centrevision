<?php

use App\Enums\WatchlistKind;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Visit;
use App\Models\WatchlistPlate;
use App\Support\Analytics\DateRange;
use App\Support\Analytics\SecurityAnalytics;
use App\Support\Analytics\TrafficAnalytics;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    #[Url(as: 'range', keep: true)]
    public string $rangeKey = '7d';

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
            $this->rangeKey = '7d';
        }
    }

    #[Computed]
    public function range(): DateRange
    {
        return DateRange::make($this->rangeKey);
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

    /**
     * All four KPIs plus their period-over-period comparisons. Fetched in one
     * place so the view is a straight render, and the labels/comparisons stay
     * in the same shape.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function kpis(): array
    {
        $range = $this->range();
        $previous = $range->previous();
        $a = $this->analytics();

        $visits = $a->totalVisits($range);
        $prevVisits = $a->totalVisits($previous);

        $unique = $a->uniqueVehicles($range);
        $prevUnique = $a->uniqueVehicles($previous);

        $returnRate = $a->returnRatePercentage($range);
        $prevReturn = $a->returnRatePercentage($previous);

        $dwell = $a->dwellSummary($range);
        $prevDwell = $a->dwellSummary($previous);

        // Everything gets the same shape so the view is a data-driven loop.
        return [
            [
                'label' => 'Total Visits',
                'value' => number_format($visits),
                'icon' => 'truck',
                'compare' => $a->comparison($visits, $prevVisits),
                'vs' => 'vs previous period',
            ],
            [
                'label' => 'Unique Vehicles',
                'value' => number_format($unique),
                'icon' => 'user-group',
                'compare' => $a->comparison($unique, $prevUnique),
                'vs' => 'vs previous period',
            ],
            [
                'label' => 'Return Rate',
                'value' => $returnRate === null ? '—' : $returnRate.'%',
                'icon' => 'arrow-path',
                'compare' => $a->comparison($returnRate, $prevReturn),
                'vs' => 'excluding staff plates',
            ],
            [
                'label' => 'Avg Dwell Time',
                'value' => $dwell['average'] === null ? '—' : $dwell['average'].' min',
                'icon' => 'clock',
                'compare' => $a->comparison($dwell['average'], $prevDwell['average']),
                'vs' => 'median '.($dwell['median'] ?? '—').' min',
            ],
        ];
    }

    /**
     * Visits per day for the current and preceding window, side by side. Two
     * short arrays so the grouped bar chart can just pluck them straight.
     *
     * @return array{labels: array<int, string>, current: array<int, int>, previous: array<int, int>}
     */
    #[Computed]
    public function visitsOverTime(): array
    {
        $current = $this->analytics->visitsByDay($this->range);
        $previous = $this->analytics->visitsByDay($this->range->previous());

        return [
            'labels' => $current->pluck('label')->all(),
            'current' => $current->pluck('count')->all(),
            // Pad or trim the previous window to line up bar-for-bar. Ranges
            // are always equal length, so this loop is a formality against
            // future changes to DateRange::previous().
            'previous' => $previous->take($current->count())->pluck('count')->all(),
        ];
    }

    /**
     * Watchlist- and security-related counts for the alert card. Grouped so
     * the shell can drive the notification bell off the same numbers.
     *
     * @return array{watchlist: int, blacklist: int, other: int, total: int}
     */
    #[Computed]
    public function alertCounts(): array
    {
        $security = app(SecurityAnalytics::class);
        $siteIds = app(Tenancy::class)->scopeSiteIds();

        // Watchlist / blacklist hits — plate_events in the last 24h that
        // match a watchlist entry. Joined once and split by kind.
        $windowStart = now()->subDay();

        $hitsByKind = PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->join('cameras', 'cameras.id', '=', 'plate_events.camera_id')
            ->join('watchlist_plates', function ($join) {
                $join->on('plate_events.plate_number', '=', 'watchlist_plates.plate_number')
                    ->on('cameras.site_id', '=', 'watchlist_plates.site_id');
            })
            ->whereIn('watchlist_plates.site_id', $siteIds)
            ->where('plate_events.captured_at', '>=', $windowStart)
            ->toBase()
            ->selectRaw('watchlist_plates.kind, COUNT(*) as hits')
            ->groupBy('watchlist_plates.kind')
            ->pluck('hits', 'kind');

        $watchlistHits = (int) ($hitsByKind[WatchlistKind::Watch->value] ?? 0)
            + (int) ($hitsByKind[WatchlistKind::Vip->value] ?? 0);
        $blacklistHits = (int) ($hitsByKind[WatchlistKind::Block->value] ?? 0);

        // "Other" = anomalies from the behavioural rules on the Security page.
        // Using the site-level dwell threshold when a site is pinned, and a
        // sensible default when the owner is looking across every site.
        $dwellHours = (int) (app(Tenancy::class)->currentSite()?->settings['dwell_alert_hours']
            ?? config('trafficflow.security.default_dwell_alert_hours', 4));

        $other = $security->overThreshold($dwellHours)->count()
            + $security->multipleEntriesToday()->count();

        return [
            'watchlist' => $watchlistHits,
            'blacklist' => $blacklistHits,
            'other' => $other,
            'total' => $watchlistHits + $blacklistHits + $other,
        ];
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
     * The last handful of visits at the tenant's site(s), used by the
     * "Latest visits" card. Owner-only: shop accounts see aggregate KPIs
     * only, never individual plates.
     *
     * @return Collection<int, Visit>
     */
    #[Computed]
    public function latestVisits(): Collection
    {
        if (! $this->canSeePlates()) {
            return collect();
        }

        return $this->analytics()
            ->recentVisits($this->range(), 6)
            ->each(fn (Visit $v) => $v->makeVisible('plate_number'));
    }

    /**
     * Security and watchlist cards are operator content, not tenant content.
     * We reuse `canSeePlates` because it is the same "you can see individual
     * vehicles" gate — shops get the aggregate view either way.
     */
    #[Computed]
    public function canSeeSecurity(): bool
    {
        return $this->canSeePlates();
    }
}; ?>

<div>
    {{-- Header — the mockup's page title + range picker + bell. Shops don't
         see the notification bell because it would only ever link to alerts
         they aren't allowed to view. --}}
    <x-dashboard-header
        :title="$this->heading"
        :subtitle="'Vehicle traffic · '.strtolower($this->range->label)"
        :alert-count="$this->canSeeSecurity ? $this->alertCounts['total'] : 0"
        :show-bell="$this->canSeeSecurity"
    >
        <x-slot:actions>
            <flux:select wire:model.live="rangeKey" size="sm" class="min-w-44" icon="calendar" label="Period" label:sr-only>
                @foreach (\App\Support\Analytics\DateRange::options() as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-dashboard-header>

    {{-- ── KPI row ────────────────────────────────────────────────────── --}}
    <div class="mb-6 grid grid-cols-4 gap-4 max-xl:grid-cols-2 max-sm:grid-cols-1">
        @foreach ($this->kpis as $kpi)
            <x-kpi-card
                :label="$kpi['label']"
                :value="$kpi['value']"
                :icon="$kpi['icon']"
                :delta="$kpi['compare']['label']"
                :delta-tone="$kpi['compare']['tone']"
                :comparison="$kpi['vs']"
            />
        @endforeach
    </div>

    {{-- ── Charts row ─────────────────────────────────────────────────── --}}
    <div class="mb-6 grid grid-cols-2 gap-4 max-lg:grid-cols-1">
        <x-panel-card>
            <x-slot:header>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Visits Over Time</p>
                    <p class="mt-1 text-sm text-ink-2">Current period vs. the previous</p>
                </div>
                <span class="rounded-full bg-surface-2 px-3 py-1 text-[11px] font-medium text-ink-2">Daily</span>
            </x-slot:header>

            <x-chart
                :labels="$this->visitsOverTime['labels']"
                :series="[
                    ['label' => 'This period', 'values' => $this->visitsOverTime['current'], 'color' => 'accent'],
                    ['label' => 'Previous', 'values' => $this->visitsOverTime['previous'], 'color' => 'accentSoft'],
                ]"
                :show-legend="true"
                :height="220"
                aria-label="Grouped bar chart comparing daily visits this period to the previous period"
            />
        </x-panel-card>

        <x-panel-card>
            <x-slot:header>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Visits by Time of Day</p>
                    <p class="mt-1 text-sm text-ink-2">All arrivals, hour by hour</p>
                </div>
                <span class="rounded-full bg-surface-2 px-3 py-1 text-[11px] font-medium text-ink-2">This period</span>
            </x-slot:header>

            <x-chart
                :labels="$this->analytics->visitsByHour($this->range)->pluck('label')->all()"
                :values="$this->analytics->visitsByHour($this->range)->pluck('count')->all()"
                :height="220"
                aria-label="Bar chart of arrivals by hour of day"
            />
        </x-panel-card>
    </div>

    {{-- ── Bottom row: entry points | alerts | recent hits ──────────────
         Shops only see the aggregate entry points; security and watchlist
         cards are owner-only, so the shop layout collapses to a single-column
         card so it doesn't leave a lonely tile floating on a wide screen. --}}
    <div @class([
        'mb-6 grid gap-4',
        'grid-cols-3 max-xl:grid-cols-2 max-md:grid-cols-1' => $this->canSeeSecurity,
        'grid-cols-1' => ! $this->canSeeSecurity,
    ])>

        {{-- Top entry points --}}
        <x-panel-card>
            <x-slot:header>
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-accent-soft text-accent">
                        <flux:icon icon="map-pin" class="size-4" />
                    </span>
                    <div>
                        <p class="text-[13px] font-semibold text-ink">Top Entry Points</p>
                        <p class="text-[11.5px] text-ink-muted">Share of arrivals per camera</p>
                    </div>
                </div>
                <a href="{{ route('cameras') }}" wire:navigate class="text-[12px] font-medium text-accent hover:underline">View all</a>
            </x-slot:header>

            @php $entries = $this->analytics->topEntryPoints($this->range); @endphp
            @if ($entries->isEmpty())
                <x-placeholder>No entrance-camera arrivals in this period.</x-placeholder>
            @else
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-[0.14em] text-ink-muted">
                            <th class="pb-2 font-semibold">Entry Point</th>
                            <th class="pb-2 text-right font-semibold">Visits</th>
                            <th class="pb-2 pl-3 text-right font-semibold">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $totalCount = $entries->sum('count') ?: 1; @endphp
                        @foreach ($entries as $entry)
                            <tr class="border-t border-line">
                                <td class="py-2 text-ink">{{ $entry['label'] }}</td>
                                <td class="py-2 text-right font-semibold tabular-nums text-ink">{{ number_format($entry['count']) }}</td>
                                <td class="py-2 pl-3 text-right tabular-nums text-ink-2">
                                    {{ number_format($entry['count'] / $totalCount * 100, 1) }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-panel-card>

        {{-- Security alerts — owner-only. --}}
        @if ($this->canSeeSecurity)
        <x-panel-card>
            <x-slot:header>
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-danger-soft text-danger">
                        <flux:icon icon="shield-exclamation" class="size-4" />
                    </span>
                    <div>
                        <p class="text-[13px] font-semibold text-ink">Security Alerts</p>
                        <p class="text-[11.5px] text-ink-muted">Last 24 hours</p>
                    </div>
                </div>
                <a href="{{ route('security') }}" wire:navigate class="text-[12px] font-medium text-accent hover:underline">View all</a>
            </x-slot:header>

            <ul class="flex flex-col divide-y divide-line">
                @foreach ([
                    ['label' => 'Watchlist Hits',  'route' => 'watchlist', 'icon' => 'bell-alert',         'tone' => 'warning', 'count' => $this->alertCounts['watchlist']],
                    ['label' => 'Blacklist Hits',  'route' => 'watchlist', 'icon' => 'shield-exclamation', 'tone' => 'danger',  'count' => $this->alertCounts['blacklist']],
                    ['label' => 'Other Alerts',    'route' => 'security',  'icon' => 'clock',              'tone' => 'accent',  'count' => $this->alertCounts['other']],
                ] as $alert)
                    <li>
                        <a href="{{ route($alert['route']) }}" wire:navigate class="group flex items-center gap-3 py-3 transition-colors first:pt-0 last:pb-0 hover:bg-surface-2">
                            <span @class([
                                'flex size-9 shrink-0 items-center justify-center rounded-full',
                                'bg-warning-soft text-warning' => $alert['tone'] === 'warning',
                                'bg-danger-soft text-danger' => $alert['tone'] === 'danger',
                                'bg-accent-soft text-accent' => $alert['tone'] === 'accent',
                            ])>
                                <flux:icon :icon="$alert['icon']" class="size-4" />
                            </span>
                            <span class="flex-1">
                                <span class="block text-[13px] font-semibold text-ink">{{ $alert['label'] }}</span>
                                <span class="block text-[11.5px] text-ink-muted">
                                    @if ($alert['count'] === 0)
                                        No new hits
                                    @else
                                        {{ $alert['count'] }} {{ $alert['count'] === 1 ? 'event' : 'events' }} in the last 24 h
                                    @endif
                                </span>
                            </span>
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-[12px] font-semibold tabular-nums',
                                'bg-warning-soft text-warning' => $alert['tone'] === 'warning',
                                'bg-danger-soft text-danger' => $alert['tone'] === 'danger',
                                'bg-accent-soft text-accent' => $alert['tone'] === 'accent',
                            ])>{{ $alert['count'] }}</span>
                            <flux:icon icon="chevron-right" class="size-4 text-ink-muted" />
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-panel-card>
        @endif

        {{-- Recent watchlist hits — owner-only. --}}
        @if ($this->canSeeSecurity)
        <x-panel-card>
            <x-slot:header>
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-accent-soft text-accent">
                        <flux:icon icon="bell-alert" class="size-4" />
                    </span>
                    <div>
                        <p class="text-[13px] font-semibold text-ink">Recent Watchlist Hits</p>
                        <p class="text-[11.5px] text-ink-muted">Latest 4 detections</p>
                    </div>
                </div>
                <a href="{{ route('watchlist') }}" wire:navigate class="text-[12px] font-medium text-accent hover:underline">View all</a>
            </x-slot:header>

            @if ($this->recentWatchlistHits->isEmpty())
                <x-placeholder>No watchlist plates seen recently.</x-placeholder>
            @elseif ($this->canSeePlates)
                <ul class="flex flex-col divide-y divide-line text-[13px]">
                    @foreach ($this->recentWatchlistHits as $hit)
                        <li class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                            <div class="min-w-0 flex-1">
                                <div class="font-mono text-[13px] font-semibold {{ $hit->kind === 'block' ? 'text-danger' : 'text-ink' }}">
                                    {{ App\Support\PlateNumber::forDisplay($hit->plate_number) }}
                                </div>
                                <div class="text-[11.5px] text-ink-muted">
                                    {{ $hit->camera_name }}
                                    · {{ \Illuminate\Support\Facades\Date::parse($hit->captured_at)->format('D H:i') }}
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-accent">
                                Cam {{ str_pad((string) $hit->camera_id, 2, '0', STR_PAD_LEFT) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <x-placeholder>Plate details are restricted to owner accounts.</x-placeholder>
            @endif
        </x-panel-card>
        @endif
    </div>

    {{-- ── Latest visits ────────────────────────────────────────────────
         Owner-only, and only rendered when we actually have plates to show:
         a shop account, or an owner with no traffic yet, gets nothing rather
         than an empty "0 rows" table. --}}
    @if ($this->canSeePlates && $this->latestVisits->isNotEmpty())
    <div class="mb-6">
        <x-panel-card>
            <x-slot:header>
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-accent-soft text-accent">
                        <flux:icon icon="truck" class="size-4" />
                    </span>
                    <div>
                        <p class="text-[13px] font-semibold text-ink">Latest visits</p>
                        <p class="text-[11.5px] text-ink-muted">Most recent vehicles detected</p>
                    </div>
                </div>
                <a href="{{ route('reports') }}" wire:navigate class="text-[12px] font-medium text-accent hover:underline">View all</a>
            </x-slot:header>

            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-[0.14em] text-ink-muted">
                        <th class="pb-2 font-semibold">Plate</th>
                        <th class="pb-2 font-semibold">Entered</th>
                        <th class="pb-2 font-semibold">Entry point</th>
                        <th class="pb-2 text-right font-semibold">Dwell</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->latestVisits as $visit)
                        <tr class="border-t border-line" wire:key="latest-visit-{{ $visit->id }}">
                            <td class="py-2 font-mono font-semibold text-ink">
                                {{ App\Support\PlateNumber::forDisplay($visit->plate_number) }}
                            </td>
                            <td class="py-2 text-ink-2">
                                {{ $visit->entered_at->format('D H:i') }}
                            </td>
                            <td class="py-2 text-ink-2">
                                {{ $visit->entryEvent?->camera?->name ?? '—' }}
                            </td>
                            <td class="py-2 text-right tabular-nums text-ink-2">
                                @if ($visit->status === \App\Enums\VisitStatus::Open)
                                    <span class="rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-accent">On site</span>
                                @else
                                    {{ number_format((int) $visit->dwell_minutes) }} min
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-panel-card>
    </div>
    @endif

    {{-- Footer band --}}
    <div class="flex items-center justify-between border-t border-line pt-4 text-[11.5px] text-ink-muted">
        <span>© {{ now()->year }} {{ config('app.name') }}. All rights reserved.</span>
        <span>Version 1.0.0</span>
    </div>
</div>
