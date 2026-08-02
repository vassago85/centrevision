<?php

use App\Support\Analytics\DateRange;
use App\Support\Analytics\TrafficAnalytics;
use App\Support\Reporting\ReportExporter;
use App\Support\Reporting\TrafficReport;
use App\Support\Tenancy;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Reports')] class extends Component {
    #[Url(as: 'range', keep: true)]
    public string $rangeKey = '30d';

    public function mount(): void
    {
        if (! array_key_exists($this->rangeKey, DateRange::options())) {
            $this->rangeKey = '30d';
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
    public function daily(): Collection
    {
        return $this->analytics()->visitsByDay($this->range());
    }

    public function exportCsv(): StreamedResponse
    {
        return app(ReportExporter::class)->csvDownload($this->report());
    }

    public function exportPdf(): Response
    {
        return app(ReportExporter::class)->pdfDownload($this->report());
    }

    protected function report(): TrafficReport
    {
        return new TrafficReport(
            $this->analytics(),
            $this->range(),
            app(Tenancy::class)->currentSite()?->name ?? 'All sites',
        );
    }

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
}; ?>

<div>
    <x-page-header
        title="Reports"
        :subtitle="(app(Tenancy::class)->currentSite()?->name ?? 'All sites').' · '.strtolower($this->range->label)"
    >
        <x-slot:actions>
            <flux:select wire:model.live="rangeKey" size="sm" class="min-w-40" label="Period" label:sr-only>
                @foreach (DateRange::options() as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button size="sm" variant="ghost" wire:click="exportCsv">CSV</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="exportPdf">PDF</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-7 grid grid-cols-4 gap-3 max-lg:grid-cols-2 max-sm:grid-cols-1">
        <x-metric label="Total visits" :value="number_format($this->summary['total'])" />
        <x-metric label="Daily average" :value="number_format($this->summary['daily_average'])" />
        <x-metric
            label="Busiest day"
            :value="$this->summary['busiest']['label'] ?? '—'"
            :delta="isset($this->summary['busiest']) ? number_format($this->summary['busiest']['count']).' vehicles' : null"
        />
        <x-metric
            label="Avg dwell"
            :value="$this->summary['average_dwell'] === null ? '—' : $this->summary['average_dwell'].' min'"
            :delta="$this->summary['median_dwell'] === null ? null : 'Median: '.$this->summary['median_dwell'].' min'"
        />
    </div>

    <x-panel heading="Visits per day">
        <x-chart
            :labels="$this->daily->pluck('label')->all()"
            :values="$this->daily->pluck('count')->all()"
            :height="240"
            aria-label="Bar chart of vehicle visits per day"
        />
    </x-panel>

    <div class="grid grid-cols-2 gap-7 max-md:grid-cols-1">
        <x-panel heading="By hour of day">
            <x-chart
                :labels="$this->analytics->visitsByHour($this->range)->pluck('label')->all()"
                :values="$this->analytics->visitsByHour($this->range)->pluck('count')->all()"
                :height="200"
                aria-label="Bar chart of vehicle visits by hour"
            />
        </x-panel>

        <x-panel heading="Dwell time distribution">
            <x-data-table :headers="['Duration', ['label' => 'Visits', 'align' => 'right'], ['label' => 'Share', 'align' => 'right']]">
                @foreach ($this->analytics->dwellDistribution($this->range) as $bucket)
                    <tr wire:key="bucket-{{ $bucket['label'] }}">
                        <td class="border-b border-line py-2">{{ $bucket['label'] }}</td>
                        <td class="border-b border-line py-2 text-right tabular-nums">{{ number_format($bucket['count']) }}</td>
                        <td class="border-b border-line py-2 text-right tabular-nums text-ink-2">{{ $bucket['percent'] }}%</td>
                    </tr>
                @endforeach
            </x-data-table>
        </x-panel>
    </div>

    <x-panel heading="Daily breakdown">
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
    </x-panel>
</div>
