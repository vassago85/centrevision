<?php

use App\Enums\WatchlistKind;
use App\Models\WatchlistPlate;
use App\Support\Analytics\SecurityAnalytics;
use App\Support\Analytics\SecurityLogExporter;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Security')] class extends Component {
    #[Url(as: 'threshold', keep: true)]
    public int $thresholdHours = 0;

    public function mount(): void
    {
        $options = $this->thresholdOptions();

        if (! in_array($this->thresholdHours, $options, true)) {
            $default = app(Tenancy::class)->currentSite()?->dwellAlertHours()
                ?? (int) config('trafficflow.dwell_alert_hours');

            $this->thresholdHours = in_array($default, $options, true) ? $default : $options[0];
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
        return $this->security()->overThreshold($this->thresholdHours);
    }

    #[Computed]
    public function oddHour(): Collection
    {
        return $this->security()->oddHourRecurring();
    }

    #[Computed]
    public function multiEntry(): Collection
    {
        return $this->security()->multipleEntriesToday();
    }

    /**
     * Flag a plate for the security team. This is the one place plate data is
     * written by hand, and it records a plate only: no owner, no description.
     */
    public function watch(int $siteId, string $plateNumber): void
    {
        $site = app(Tenancy::class)->sites()->firstWhere('id', $siteId);

        abort_if($site === null, 403);
        $this->authorize('viewSecurity', $site);

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
     * Stream every plate detection for the day as CSV. Only the site owner
     * ever reaches this action (route middleware + policy), so the plate
     * numbers this export contains stay inside the trust boundary POPIA
     * defines for their site.
     */
    public function downloadTodayLog()
    {
        $tenancy = app(Tenancy::class);
        $site = $tenancy->currentSite();

        abort_if($site === null, 400, 'Choose a site before exporting a log.');

        $this->authorize('viewSecurity', $site);

        return app(SecurityLogExporter::class)->streamDay($site, now());
    }
}; ?>

<div wire:poll.60s>
    <x-page-header title="Security · dwell alerts" :subtitle="(app(App\Support\Tenancy::class)->currentSite()?->name ?? 'All sites').' · live'">
        <x-slot:actions>
            @if (app(App\Support\Tenancy::class)->currentSite() !== null)
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="arrow-down-tray"
                    wire:click="downloadTodayLog"
                >Download today's log</flux:button>
            @endif
            <flux:select wire:model.live="thresholdHours" size="sm" class="min-w-36" label="Threshold" label:sr-only>
                @foreach ($this->thresholdOptions() as $hours)
                    <flux:select.option :value="$hours">{{ $hours }} hours</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-7 grid grid-cols-4 gap-3 max-lg:grid-cols-2 max-sm:grid-cols-1">
        <x-metric
            label="Over threshold now"
            :value="$this->overThreshold->count()"
            :variant="$this->overThreshold->isEmpty() ? 'default' : 'danger'"
        />
        <x-metric
            label="Odd-hour recurring"
            :value="$this->oddHour->count()"
            :delta="'last '.config('trafficflow.security.odd_hour_window_days').' days'"
        />
        <x-metric
            label="Multi-entry today"
            :value="$this->multiEntry->count()"
            :delta="config('trafficflow.security.multi_entry_threshold').'+ entries, same plate'"
        />
        <x-metric label="No exit recorded" :value="$this->security->orphanedCount()" delta="last 7 days" />
    </div>

    <x-panel heading="Currently over threshold">
        <x-data-table
            :headers="['Plate', 'Entered', 'Camera', ['label' => 'On-site', 'align' => 'right'], ['label' => '', 'align' => 'right']]"
            :is-empty="$this->overThreshold->isEmpty()"
            empty="Nothing has been on site longer than {{ $thresholdHours }} hours."
        >
            @foreach ($this->overThreshold as $visit)
                @php
                    $minutes = $visit->minutesOnSite();
                @endphp

                <tr wire:key="over-{{ $visit->id }}">
                    <td class="border-b border-line py-2"><x-plate :number="$visit->plate_number" /></td>
                    <td class="border-b border-line py-2">{{ $visit->entered_at->format('D H:i') }}</td>
                    <td class="border-b border-line py-2 text-ink-2">
                        {{ $visit->entryEvent?->camera?->name ?? $visit->site->name }}
                    </td>
                    <td @class([
                        'border-b border-line py-2 text-right tabular-nums font-medium',
                        'text-danger' => $minutes >= ($thresholdHours + 1) * 60,
                        'text-warning' => $minutes < ($thresholdHours + 1) * 60,
                    ])>{{ $this->onSiteFor($minutes) }}</td>
                    <td class="border-b border-line py-2 text-right">
                        <flux:button
                            size="xs"
                            variant="ghost"
                            wire:click="watch({{ $visit->site_id }}, '{{ $visit->plate_number }}')"
                        >Watch</flux:button>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>

    <div class="grid grid-cols-2 gap-7 max-md:grid-cols-1">
        <x-panel heading="Odd-hour recurring visits">
            <x-data-table
                :headers="['Plate', 'Days seen', ['label' => 'Typical time', 'align' => 'right']]"
                :is-empty="$this->oddHour->isEmpty()"
                empty="No plates seen repeatedly in the small hours."
            >
                @foreach ($this->oddHour as $row)
                    <tr wire:key="odd-{{ $row['plate_number'] }}">
                        <td class="border-b border-line py-2"><x-plate :number="$row['plate_number']" /></td>
                        <td class="border-b border-line py-2 text-ink-2">{{ $row['days'] }} of {{ $row['window_days'] }}</td>
                        <td class="border-b border-line py-2 text-right tabular-nums">{{ $row['typical_time'] }}</td>
                    </tr>
                @endforeach
            </x-data-table>
        </x-panel>

        <x-panel heading="Multiple entries today">
            <x-data-table
                :headers="['Plate', 'Entry times', ['label' => 'Entries', 'align' => 'right']]"
                :is-empty="$this->multiEntry->isEmpty()"
                empty="No plate has re-entered enough times today to flag."
            >
                @foreach ($this->multiEntry as $row)
                    <tr wire:key="multi-{{ $row['plate_number'] }}">
                        <td class="border-b border-line py-2 align-top"><x-plate :number="$row['plate_number']" /></td>
                        <td class="border-b border-line py-2 text-ink-2 tabular-nums">{{ implode(', ', $row['times']) }}</td>
                        <td class="border-b border-line py-2 text-right tabular-nums align-top">{{ $row['entries'] }}</td>
                    </tr>
                @endforeach
            </x-data-table>
        </x-panel>
    </div>
</div>

