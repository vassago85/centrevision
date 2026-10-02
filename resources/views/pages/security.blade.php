<?php

use App\Enums\PlateDirection;
use App\Enums\WatchlistKind;
use App\Models\AlertEvent;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\WatchlistPlate;
use App\Support\Analytics\SecurityAnalytics;
use App\Support\Analytics\SecurityLogExporter;
use App\Support\Ingestion\PlateCaptureStore;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Security')] class extends Component {
    #[Url(as: 'threshold', keep: true)]
    public int $thresholdHours = 0;

    /**
     * Optional per-camera filter. Only active when the current site has
     * more than one camera — a single-camera site would only ever offer
     * "All / that one".
     */
    #[Url(as: 'camera', keep: true)]
    public ?int $cameraId = null;

    /**
     * The date whose plate-detection log the owner is about to download.
     * Defaults to today, and is capped to today in the UI so a future date
     * cannot be requested. Any historic day with data is valid — an empty
     * day just produces a CSV with only the header row.
     */
    public string $logDate = '';

    /** Event whose snapshots are open in the photo modal. */
    public ?int $viewingCaptureEventId = null;

    /** Which actionable list is open. */
    #[Url(as: 'view', keep: true)]
    public string $view = 'dwell';

    public function mount(): void
    {
        if (! array_key_exists($this->view, $this->views())) {
            $this->view = 'dwell';
        }

        $options = $this->thresholdOptions();

        if (! in_array($this->thresholdHours, $options, true)) {
            $default = app(Tenancy::class)->currentSite()?->dwellAlertHours()
                ?? (int) config('trafficflow.dwell_alert_hours');

            $this->thresholdHours = in_array($default, $options, true) ? $default : $options[0];
        }

        if ($this->logDate === '') {
            $this->logDate = now()->toDateString();
        }

        $this->normaliseCameraId();

        // Landing on the security page counts as acknowledging any pending
        // alerts — this is what clears the dashboard bell for the current
        // user until new events arrive.
        auth()->user()?->markAlertsSeen();
    }

    public function updatedCameraId(): void
    {
        $this->normaliseCameraId();
    }

    public function updatedView(): void
    {
        if (! array_key_exists($this->view, $this->views())) {
            $this->view = 'dwell';
        }
    }

    /**
     * @return array<string, string>
     */
    public function views(): array
    {
        return [
            'dwell' => 'Over threshold',
            'odd' => 'Odd hours',
            'multi' => 'Multiple entries',
            'detections' => 'Latest detections',
            'emails' => 'Alert emails',
        ];
    }

    /**
     * Tab labels with live counts so the busy list is obvious before it is opened.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function viewTabs(): array
    {
        $counts = [
            'dwell' => $this->overThreshold->count(),
            'odd' => $this->oddHour->count(),
            'multi' => $this->multiEntry->count(),
        ];

        return collect($this->views())
            ->map(fn (string $label, string $key) => isset($counts[$key]) ? $label.' ('.$counts[$key].')' : $label)
            ->all();
    }

    /**
     * Null when there is no single site to configure, so the prompt only
     * appears where switching alerts on is actually possible.
     */
    #[Computed]
    public function alertsEnabled(): ?bool
    {
        $site = app(Tenancy::class)->currentSite();

        if ($site === null) {
            return null;
        }

        $alerts = $site->setting('alerts', []);

        return (bool) (is_array($alerts) ? ($alerts['enabled'] ?? false) : false);
    }

    #[Computed]
    public function canManageSettings(): bool
    {
        return (bool) auth()->user()?->isOwnerAdmin();
    }

    #[Computed]
    public function captureRetentionLabel(): string
    {
        return PlateCaptureStore::retentionLabel();
    }

    /**
     * Cameras of the current site — used to render the filter and to
     * decide whether it's worth rendering at all.
     *
     * @return Collection<int, Camera>
     */
    #[Computed]
    public function cameras(): Collection
    {
        $site = app(Tenancy::class)->currentSite();

        if ($site === null) {
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

    /**
     * @return array<int, int>
     */
    public function thresholdOptions(): array
    {
        return array_map('intval', config('trafficflow.dwell_alert_options'));
    }

    #[Computed]
    public function security(): SecurityAnalytics
    {
        return app(SecurityAnalytics::class);
    }

    #[Computed]
    public function overThreshold(): Collection
    {
        return $this->security()->overThreshold($this->thresholdHours, $this->cameraId);
    }

    #[Computed]
    public function oddHour(): Collection
    {
        return $this->security()->oddHourRecurring($this->cameraId);
    }

    #[Computed]
    public function multiEntry(): Collection
    {
        return $this->security()->multipleEntriesToday($this->cameraId);
    }

    /**
     * @return Collection<int, AlertEvent>
     */
    #[Computed]
    public function recentAlertEmails(): Collection
    {
        $site = app(Tenancy::class)->currentSite();

        if ($site === null) {
            return collect();
        }

        return AlertEvent::query()
            ->where('site_id', $site->getKey())
            ->orderByDesc('detected_at')
            ->limit(20)
            ->get();
    }

    /**
     * Recent detections for the desk log. Photos only exist for a day, so
     * the window matches that — a Photos button is shown only when a JPEG
     * is still on disk.
     *
     * @return Collection<int, PlateEvent>
     */
    #[Computed]
    public function latestDetections(): Collection
    {
        $events = PlateEvent::query()
            ->with('camera:id,name')
            ->when($this->cameraId, fn ($query, $id) => $query->where('camera_id', $id))
            ->where('captured_at', '>=', now()->subDay())
            ->orderByDesc('captured_at')
            ->limit(15)
            ->get();

        $store = app(PlateCaptureStore::class);

        return $events->each(function (PlateEvent $event) use ($store): void {
            $event->setAttribute('capture_count', count($store->pathsFor($event)));
        });
    }

    #[Computed]
    public function viewingCaptureEvent(): ?PlateEvent
    {
        if ($this->viewingCaptureEventId === null) {
            return null;
        }

        $event = PlateEvent::query()->with('camera')->find($this->viewingCaptureEventId);

        return $event === null || auth()->user()?->cannot('view', $event) ? null : $event;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function viewingCaptureUrls(): array
    {
        $event = $this->viewingCaptureEvent;

        if ($event === null) {
            return [];
        }

        $count = count(app(PlateCaptureStore::class)->pathsFor($event));

        if ($count === 0) {
            return [];
        }

        return collect(range(0, $count - 1))
            ->map(fn (int $index) => route('activity.captures.show', [$event, $index]))
            ->all();
    }

    public function viewCaptures(int $eventId): void
    {
        $this->viewingCaptureEventId = $eventId;
    }

    public function closeCaptures(): void
    {
        $this->viewingCaptureEventId = null;
    }

    /**
     * True when the currently-viewed site has no camera that can report
     * exits. Dwell-based alerts are meaningless in that case, so the UI
     * shows a small note explaining why those figures are always zero.
     */
    #[Computed]
    public function isEntryOnly(): bool
    {
        $site = app(Tenancy::class)->currentSite();

        return $site !== null && ! $site->hasExitTracking();
    }

    /**
     * Flag a plate for the security team. This is the one place plate data is
     * written by hand, and it records a plate only: no owner, no description.
     */
    public function watch(int $siteId, string $plateNumber): void
    {
        $site = app(Tenancy::class)->sites()->firstWhere('id', $siteId);

        abort_if($site === null, 403);
        $this->authorize('manageWatchlist', $site);

        WatchlistPlate::updateOrCreate(
            ['site_id' => $site->getKey(), 'plate_number' => $plateNumber],
            [
                'kind' => WatchlistKind::Watch,
                'reason' => 'Flagged from dwell alert',
                'added_by_user_id' => auth()->id(),
            ],
        );

        unset($this->overThreshold);

        Flux::toast(variant: 'success', text: $plateNumber.' added to the watchlist.');
    }

    /**
     * How long a still-open visit has been on site, as "6h 40m".
     */
    public function onSiteFor(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /**
     * Stream every plate detection for the picked day as CSV. Only the site
     * owner ever reaches this action (route middleware + policy), so the
     * plate numbers this export contains stay inside the trust boundary
     * POPIA defines for their site. Refuses future dates and dates outside
     * the site's retention window as a courtesy — the pruning job will
     * already have wiped anything older.
     */
    public function downloadLog()
    {
        $tenancy = app(Tenancy::class);
        $site = $tenancy->currentSite();

        abort_if($site === null, 400, 'Choose a site before exporting a log.');

        $this->authorize('viewSecurity', $site);

        try {
            $date = \Illuminate\Support\Facades\Date::parse($this->logDate);
        } catch (\Throwable) {
            Flux::toast(variant: 'danger', text: 'Pick a valid date to download.');

            return;
        }

        if ($date->isAfter(now()->endOfDay())) {
            Flux::toast(variant: 'danger', text: 'Cannot export a future date.');

            return;
        }

        return app(SecurityLogExporter::class)->streamDay($site, $date, $this->cameraId);
    }
}; ?>

{{-- 30s cadence matches the dashboard's alertCounts refresh so the bell and
     this page never disagree by more than one poll cycle. --}}
<div wire:poll.30s>
    <x-page-header title="Security" :subtitle="(app(App\Support\Tenancy::class)->currentSite()?->name ?? 'All sites').' · live · refreshes every 30 seconds'">
        <x-slot:actions>
            @if (app(App\Support\Tenancy::class)->currentSite() !== null)
                <div class="flex items-center gap-2">
                    <flux:input
                        type="date"
                        wire:model="logDate"
                        :max="now()->toDateString()"
                        size="sm"
                        class="w-40"
                        label="Log date"
                        label:sr-only
                    />
                    <flux:button
                        size="sm"
                        icon="arrow-down-tray"
                        wire:click="downloadLog"
                    >Download log</flux:button>
                </div>
            @endif
        </x-slot:actions>
    </x-page-header>

    <section aria-label="Filters" class="mb-4 flex flex-wrap items-end gap-3 rounded-tf border border-line bg-surface p-4">
        <flux:select wire:model.live="thresholdHours" class="w-40" label="Dwell threshold">
            @foreach ($this->thresholdOptions() as $hours)
                <flux:select.option :value="$hours">{{ $hours }} hours</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->hasMultipleCameras)
            <flux:select wire:model.live="cameraId" class="w-48" label="Camera">
                <flux:select.option :value="null">All cameras</flux:select.option>
                @foreach ($this->cameras as $camera)
                    <flux:select.option :value="$camera->id">{{ $camera->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ($this->alertsEnabled === false)
            <div class="ml-auto flex flex-wrap items-center gap-3 text-[13px] text-ink-2">
                <span>Email alerts are off for this site.</span>
                @if ($this->canManageSettings)
                    <flux:button size="sm" icon="bell-alert" :href="route('settings', ['tab' => 'alerts'])" wire:navigate data-test="enable-alerts">Enable alerts</flux:button>
                @endif
            </div>
        @endif
    </section>

    @if ($this->isEntryOnly)
        <x-notice tone="info" class="mb-4" title="This site has no exit camera.">
            Dwell alerts and missing-exit figures can't be produced without an exit-capable camera, so they will always read zero. Entry-based alerts (odd-hour arrivals, multiple entries today) work as normal.
        </x-notice>
    @endif

    {{-- Severity is deliberately conservative: danger only when vehicles are
         over the dwell threshold, warn for odd-hour / multi-entry, and missing
         exits stay neutral — they are almost always a camera or matching
         problem, so the card links to System health instead. --}}
    <div class="mb-4 grid grid-cols-4 gap-4 max-lg:grid-cols-2 max-sm:grid-cols-1">
        <x-metric
            label="Over dwell"
            :value="$this->overThreshold->count()"
            :variant="$this->overThreshold->isEmpty() ? 'default' : 'danger'"
            :delta="$this->isEntryOnly ? 'requires exit camera' : 'on site longer than '.$thresholdHours.' hours'"
        />
        <x-metric
            label="Odd hours"
            :value="$this->oddHour->count()"
            :variant="$this->oddHour->isEmpty() ? 'default' : 'warn'"
            :delta="'recurring small-hours visits · last '.config('trafficflow.security.odd_hour_window_days').' days'"
        />
        <x-metric
            label="Multiple entries"
            :value="$this->multiEntry->count()"
            :variant="$this->multiEntry->isEmpty() ? 'default' : 'warn'"
            :delta="config('trafficflow.security.multi_entry_threshold').'+ entries today, same plate'"
        />
        @php $missingExit = $this->security->orphanedCount(7, $this->cameraId); @endphp
        <a
            href="{{ route('reports', ['tab' => 'health']) }}"
            wire:navigate
            class="group block rounded-tf border border-line bg-surface p-4 text-left transition-colors hover:border-accent/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            data-test="missing-exits"
        >
            <p class="mb-1 text-[13px] font-medium text-ink-2">Missing exits</p>
            <p class="text-[24px] font-semibold leading-tight text-ink tabular-nums">{{ number_format($missingExit) }}</p>
            <p class="mt-1.5 text-[12.5px] text-ink-muted group-hover:text-ink-2">
                {{ $this->isEntryOnly ? 'requires exit camera' : 'last 7 days · usually a pairing issue · see System health' }}
            </p>
        </a>
    </div>

    <x-panel-card padding="p-0">
        <x-tabs :tabs="$this->viewTabs" :current="$view" model="view" label="Security lists" class="px-2" />

        <div class="p-4 sm:p-5" role="tabpanel" aria-labelledby="tab-{{ $view }}">
            @switch($view)
                @case('odd')
                    <p class="mb-3 text-[13px] text-ink-muted">Plates seen repeatedly in the small hours over the last {{ config('trafficflow.security.odd_hour_window_days') }} days.</p>
                    <x-data-table
                        :headers="['Plate', 'Days seen', ['label' => 'Typical time', 'align' => 'right'], ['label' => '', 'align' => 'right']]"
                        :is-empty="$this->oddHour->isEmpty()"
                        empty="No plates seen repeatedly in the small hours."
                    >
                        @foreach ($this->oddHour as $row)
                            <tr wire:key="odd-{{ $row['plate_number'] }}">
                                <td class="border-b border-line py-2"><x-plate :number="$row['plate_number']" /></td>
                                <td class="border-b border-line py-2 text-ink-2">{{ $row['days'] }} of {{ $row['window_days'] }}</td>
                                <td class="border-b border-line py-2 text-right tabular-nums">{{ $row['typical_time'] }}</td>
                                <td class="border-b border-line py-2 text-right">
                                    <flux:button size="sm" variant="ghost" :href="route('vehicle', ['plate' => $row['plate_number']])" wire:navigate>History</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </x-data-table>
                    @break

                @case('multi')
                    <p class="mb-3 text-[13px] text-ink-muted">Plates that entered {{ config('trafficflow.security.multi_entry_threshold') }} or more times today.</p>
                    <x-data-table
                        :headers="['Plate', 'Entry times', ['label' => 'Entries', 'align' => 'right'], ['label' => '', 'align' => 'right']]"
                        :is-empty="$this->multiEntry->isEmpty()"
                        empty="No plate has re-entered enough times today to flag."
                    >
                        @foreach ($this->multiEntry as $row)
                            <tr wire:key="multi-{{ $row['plate_number'] }}">
                                <td class="border-b border-line py-2 align-top"><x-plate :number="$row['plate_number']" /></td>
                                <td class="border-b border-line py-2 text-ink-2 tabular-nums">{{ implode(', ', $row['times']) }}</td>
                                <td class="border-b border-line py-2 text-right tabular-nums align-top">{{ $row['entries'] }}</td>
                                <td class="border-b border-line py-2 text-right align-top">
                                    <flux:button size="sm" variant="ghost" :href="route('vehicle', ['plate' => $row['plate_number']])" wire:navigate>History</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </x-data-table>
                    @break

                @case('detections')
                    <p class="mb-3 text-[13px] text-ink-muted">The 15 most recent detections in the last 24 hours. Full search is on the Activity page.</p>
                    <x-data-table
                        :headers="[
                            'Time',
                            'Plate',
                            'Camera',
                            'Direction',
                            ['label' => 'Confidence', 'align' => 'right'],
                            ['label' => '', 'align' => 'right'],
                        ]"
                        :is-empty="$this->latestDetections->isEmpty()"
                        empty="No detections in the last 24 hours."
                    >
                        @foreach ($this->latestDetections as $event)
                            @php
                                $isIn = $event->direction === PlateDirection::In;
                                $conf = $event->confidence === null ? null : (int) round($event->confidence * 100);
                            @endphp
                            <tr wire:key="latest-detection-{{ $event->id }}">
                                <td class="whitespace-nowrap border-b border-line py-2 tabular-nums text-ink-2">
                                    {{ $event->captured_at->format('D d M · H:i') }}
                                </td>
                                <td class="whitespace-nowrap border-b border-line py-2 font-mono font-semibold text-ink">
                                    {{ App\Support\PlateNumber::forDisplay($event->plate_number) }}
                                </td>
                                <td class="border-b border-line py-2 text-ink-2">
                                    {{ $event->camera?->name ?? '—' }}
                                </td>
                                <td class="border-b border-line py-2">
                                    @if ($event->direction === null)
                                        <span class="text-[12.5px] text-ink-muted">Unknown</span>
                                    @else
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[12px] font-semibold',
                                            'bg-accent-soft text-accent' => $isIn,
                                            'bg-warning-soft text-warning' => ! $isIn,
                                        ])>
                                            <flux:icon :icon="$isIn ? 'arrow-down-right' : 'arrow-up-left'" class="size-3" />
                                            {{ $isIn ? 'In' : 'Out' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="border-b border-line py-2 text-right">
                                    @if ($conf === null)
                                        <span class="text-[12.5px] text-ink-muted">—</span>
                                    @else
                                        <span @class([
                                            'text-[12.5px] tabular-nums',
                                            'text-warning' => $conf < 85,
                                            'text-ink-2' => $conf >= 85,
                                        ])>{{ $conf }}%</span>
                                    @endif
                                </td>
                                <td class="border-b border-line py-2 text-right">
                                    <div class="flex items-center justify-end gap-1 whitespace-nowrap">
                                        @if ($event->getAttribute('capture_count') > 0)
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="photo"
                                                wire:click="viewCaptures({{ $event->id }})"
                                                data-test="view-captures-{{ $event->id }}"
                                            >Photos</flux:button>
                                        @endif
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            :href="route('vehicle', ['plate' => $event->plate_number])"
                                            wire:navigate
                                        >History</flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </x-data-table>
                    @break

                @case('emails')
                    <p class="mb-3 text-[13px] text-ink-muted">The 20 most recent alert emails for this site.</p>
                    @if ($this->recentAlertEmails->isEmpty())
                        <x-empty-state title="No alert emails sent yet" icon="envelope">
                            {{ $this->alertsEnabled ? 'Emails appear here when a rule fires.' : 'Email alerts are switched off for this site.' }}
                            @if (! $this->alertsEnabled && $this->canManageSettings)
                                <x-slot:action>
                                    <flux:button size="sm" :href="route('settings', ['tab' => 'alerts'])" wire:navigate>Enable alerts</flux:button>
                                </x-slot:action>
                            @endif
                        </x-empty-state>
                    @else
                        <x-data-table :headers="['When', 'Rule', 'Plate', 'Status']">
                            @foreach ($this->recentAlertEmails as $alert)
                                <tr wire:key="alert-{{ $alert->id }}">
                                    <td class="whitespace-nowrap border-b border-line py-2">{{ $alert->detected_at->format('D d M · H:i') }}</td>
                                    <td class="border-b border-line py-2">{{ $alert->rule->label() }}</td>
                                    <td class="border-b border-line py-2"><x-plate :number="$alert->plate_number" /></td>
                                    <td class="border-b border-line py-2">
                                        <x-badge>{{ $alert->status->label() }}</x-badge>
                                    </td>
                                </tr>
                            @endforeach
                        </x-data-table>
                    @endif
                    @break

                @default
                    <p class="mb-3 text-[13px] text-ink-muted">Vehicles still on site longer than {{ $thresholdHours }} hours, longest first.</p>
                    <x-data-table
                        :headers="['Plate', 'Entered', 'Camera', ['label' => 'On site', 'align' => 'right'], ['label' => '', 'align' => 'right']]"
                        :is-empty="$this->overThreshold->isEmpty()"
                        empty="Nothing has been on site longer than {{ $thresholdHours }} hours."
                    >
                        @foreach ($this->overThreshold as $visit)
                            @php
                                $minutes = $visit->minutesOnSite();
                            @endphp

                            <tr wire:key="over-{{ $visit->id }}">
                                <td class="border-b border-line py-2"><x-plate :number="$visit->plate_number" /></td>
                                <td class="whitespace-nowrap border-b border-line py-2">{{ $visit->entered_at->format('D H:i') }}</td>
                                <td class="border-b border-line py-2 text-ink-2">
                                    {{ $visit->entryEvent?->camera?->name ?? $visit->site->name }}
                                </td>
                                <td @class([
                                    'border-b border-line py-2 text-right tabular-nums font-medium',
                                    'text-danger' => $minutes >= ($thresholdHours + 1) * 60,
                                    'text-warning' => $minutes < ($thresholdHours + 1) * 60,
                                ])>{{ $this->onSiteFor($minutes) }}</td>
                                <td class="border-b border-line py-2 text-right">
                                    <div class="flex items-center justify-end gap-1 whitespace-nowrap">
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            :href="route('vehicle', ['plate' => $visit->plate_number])"
                                            wire:navigate
                                        >History</flux:button>
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="watch({{ $visit->site_id }}, '{{ $visit->plate_number }}')"
                                        >Watch</flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </x-data-table>
            @endswitch
        </div>
    </x-panel-card>

    <flux:modal wire:model.self="viewingCaptureEventId" class="w-full md:w-[52rem]" @close="$wire.closeCaptures()">
        <x-photo-viewer :event="$this->viewingCaptureEvent" :urls="$this->viewingCaptureUrls" :retention="$this->captureRetentionLabel" />
    </flux:modal>
</div>
