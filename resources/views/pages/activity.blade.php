<?php

use App\Enums\PlateDirection;
use App\Enums\WatchlistKind;
use App\Models\Camera;
use App\Models\WatchlistPlate;
use App\Support\Analytics\PlateActivityLog;
use App\Support\Analytics\SecurityLogExporter;
use App\Support\PlateNumber;
use App\Support\Tenancy;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activity')] class extends Component
{
    use WithPagination;

    /** ISO date, inclusive lower bound. Defaults to 7 days ago. */
    #[Url(as: 'from', keep: true)]
    public string $fromDate = '';

    /** ISO date, inclusive upper bound. Defaults to today. */
    #[Url(as: 'to', keep: true)]
    public string $toDate = '';

    /** Optional camera filter; ignored when the site has 0-1 active cameras. */
    #[Url(as: 'camera', keep: true)]
    public ?int $cameraId = null;

    /** Plate search — substring match on the normalised form. */
    #[Url(as: 'plate', keep: true)]
    public string $plateSearch = '';

    public function mount(): void
    {
        if ($this->toDate === '') {
            $this->toDate = now()->toDateString();
        }

        if ($this->fromDate === '') {
            $this->fromDate = now()->subDays(6)->toDateString();
        }

        $this->normaliseCameraId();
    }

    /**
     * Watchers so URL-driven changes still reset pagination and drop the
     * camera filter when the site loses its extra cameras between renders.
     */
    public function updatedFromDate(): void
    {
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->resetPage();
    }

    public function updatedCameraId(): void
    {
        $this->normaliseCameraId();
        $this->resetPage();
    }

    public function updatedPlateSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Cameras the operator can pick between. The Camera model's global
     * SiteScope already narrows to what the tenant can reach — the
     * picked site when one is selected, or every accessible site when
     * the switcher is on "All sites". We deliberately do NOT filter to
     * currentSite() again here, because that collapsed the list to
     * nothing whenever an owner viewed all their sites and made the
     * "Cameras" summary metric render as 0 next to a table that was
     * clearly showing detections.
     *
     * @return Collection<int, Camera>
     */
    #[Computed]
    public function cameras(): Collection
    {
        return Camera::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * True when a per-camera filter is worth showing. A site with a single
     * camera would only ever offer "All / that one", which is noise.
     */
    #[Computed]
    public function hasMultipleCameras(): bool
    {
        return $this->cameras->count() > 1;
    }

    /**
     * The dropped-into-view name of the plate currently being drilled
     * into, or null when the full log is showing.
     */
    #[Computed]
    public function focusedPlate(): ?string
    {
        return $this->plateSearch === '' ? null : $this->plateSearch;
    }

    #[Computed]
    public function events(): LengthAwarePaginator
    {
        [$from, $to] = $this->window();

        return app(PlateActivityLog::class)->paginate(
            $from,
            $to,
            $this->cameraId,
            $this->plateSearch === '' ? null : $this->plateSearch,
            perPage: 50,
        );
    }

    #[Computed]
    public function uniquePlateCount(): int
    {
        [$from, $to] = $this->window();

        return app(PlateActivityLog::class)->uniquePlates(
            $from,
            $to,
            $this->cameraId,
            $this->plateSearch === '' ? null : $this->plateSearch,
        );
    }

    /**
     * Drill into a plate: keeps the current date range and camera filter,
     * swaps the log for that plate's entries and exits.
     */
    public function focusOnPlate(string $plateNumber): void
    {
        $this->plateSearch = PlateNumber::normalise($plateNumber);
        $this->resetPage();
    }

    public function clearPlateFocus(): void
    {
        $this->plateSearch = '';
        $this->resetPage();
    }

    /**
     * Add the currently-focused plate to the watchlist. Only reachable
     * when a plate is focused, so the policy check has something concrete
     * to authorise against.
     */
    public function watchFocusedPlate(): void
    {
        $site = app(Tenancy::class)->currentSite();
        $plate = $this->focusedPlate;

        if ($site === null || $plate === null) {
            Flux::toast(variant: 'danger', text: 'Focus on a plate first.');

            return;
        }

        $this->authorize('manageWatchlist', $site);

        WatchlistPlate::updateOrCreate(
            ['site_id' => $site->getKey(), 'plate_number' => PlateNumber::normalise($plate)],
            [
                'kind' => WatchlistKind::Watch,
                'reason' => 'Flagged from activity log',
                'added_by_user_id' => auth()->id(),
            ],
        );

        Flux::toast(variant: 'success', text: PlateNumber::forDisplay($plate).' added to the watchlist.');
    }

    /**
     * CSV of every event that matches the current filters, for the day the
     * operator picked. Reuses the existing SecurityLogExporter so plate
     * exports still travel one code path.
     */
    public function downloadDay()
    {
        $site = app(Tenancy::class)->currentSite();

        abort_if($site === null, 400, 'Choose a site before exporting a log.');
        $this->authorize('viewSecurity', $site);

        try {
            $date = Date::parse($this->toDate);
        } catch (\Throwable) {
            Flux::toast(variant: 'danger', text: 'Pick a valid date to download.');

            return;
        }

        if ($date->isAfter(now()->endOfDay())) {
            Flux::toast(variant: 'danger', text: 'Cannot export a future date.');

            return;
        }

        return app(SecurityLogExporter::class)
            ->streamDay($site, $date, $this->cameraId);
    }

    /**
     * Snap the picked ISO dates onto full-day boundaries in the site's
     * timezone. Returning them here means every downstream method reads
     * from one place — a mis-typed date can't produce a "today only"
     * query on one card and a "wide open" query on another.
     *
     * @return array{0: \Carbon\CarbonInterface, 1: \Carbon\CarbonInterface}
     */
    protected function window(): array
    {
        try {
            $from = Date::parse($this->fromDate)->startOfDay();
        } catch (\Throwable) {
            $from = now()->subDays(6)->startOfDay();
        }

        try {
            $to = Date::parse($this->toDate)->endOfDay();
        } catch (\Throwable) {
            $to = now()->endOfDay();
        }

        // Guarantee $from <= $to so an operator who types a reversed range
        // still sees a sensible window rather than an empty table.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /**
     * Drop a camera filter that no longer matches the current site — for
     * example the site was switched to one that doesn't own that camera,
     * or the camera was retired.
     */
    protected function normaliseCameraId(): void
    {
        if ($this->cameraId === null) {
            return;
        }

        if (! $this->cameras->contains('id', $this->cameraId)) {
            $this->cameraId = null;
        }
    }
}; ?>

<div>
    <x-page-header
        title="Activity"
        :subtitle="(app(Tenancy::class)->currentSite()?->name ?? 'All sites').' · every plate detection'"
    >
        <x-slot:actions>
            @if (app(Tenancy::class)->currentSite() !== null)
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="arrow-down-tray"
                    wire:click="downloadDay"
                >Download {{ $this->toDate }}</flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-panel heading="Filters">
        <div class="grid gap-3 rounded-tf border border-line bg-surface p-4 md:grid-cols-4">
            <flux:input
                wire:model.live.debounce.300ms="fromDate"
                type="date"
                :max="now()->toDateString()"
                label="From"
            />

            <flux:input
                wire:model.live.debounce.300ms="toDate"
                type="date"
                :max="now()->toDateString()"
                label="To"
            />

            @if ($this->hasMultipleCameras)
                <flux:select wire:model.live="cameraId" label="Camera">
                    <flux:select.option :value="null">All cameras</flux:select.option>
                    @foreach ($this->cameras as $camera)
                        <flux:select.option :value="$camera->id">{{ $camera->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input
                wire:model.live.debounce.500ms="plateSearch"
                label="Plate"
                placeholder="Search e.g. JD45GP"
            />
        </div>
    </x-panel>

    @if ($this->focusedPlate)
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface-2 px-4 py-3 text-[13px]">
            <div class="flex items-center gap-3">
                <flux:icon icon="magnifying-glass" class="size-4 text-accent" />
                <span class="text-ink-2">
                    Showing history for
                    <span class="ml-1 font-mono font-semibold text-ink">{{ App\Support\PlateNumber::forDisplay($this->focusedPlate) }}</span>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <flux:button size="xs" variant="ghost" wire:click="watchFocusedPlate">Add to watchlist</flux:button>
                <flux:button size="xs" variant="ghost" wire:click="clearPlateFocus">Clear</flux:button>
            </div>
        </div>
    @endif

    <div class="mb-7 grid grid-cols-3 gap-3 max-sm:grid-cols-1">
        <x-metric
            label="Events"
            :value="number_format($this->events->total())"
            :delta="'range: '.($this->fromDate).' → '.($this->toDate)"
        />
        <x-metric
            label="Unique plates"
            :value="number_format($this->uniquePlateCount)"
        />
        <x-metric
            label="Cameras"
            :value="$this->hasMultipleCameras ? ($this->cameraId ? '1 selected' : $this->cameras->count().' active') : $this->cameras->count()"
            :delta="$this->cameraId ? optional($this->cameras->firstWhere('id', $this->cameraId))->name : null"
        />
    </div>

    <x-panel :heading="$this->focusedPlate ? 'Plate history' : 'All detections'">
        <x-data-table
            :headers="[
                'Time',
                'Plate',
                'Camera',
                'Direction',
                ['label' => 'Confidence', 'align' => 'right'],
                ['label' => '', 'align' => 'right'],
            ]"
            :is-empty="$this->events->isEmpty()"
            empty="No plate detections match those filters."
        >
            @foreach ($this->events as $event)
                @php
                    $isIn = $event->direction === PlateDirection::In;
                    $conf = $event->confidence === null ? null : (int) round($event->confidence * 100);
                @endphp
                <tr wire:key="event-{{ $event->id }}">
                    <td class="border-b border-line py-2 tabular-nums text-ink-2">
                        {{ $event->captured_at->format('D d M · H:i') }}
                    </td>
                    <td class="border-b border-line py-2">
                        <button
                            type="button"
                            wire:click="focusOnPlate('{{ $event->plate_number }}')"
                            class="font-mono font-semibold text-ink hover:text-accent"
                        >{{ App\Support\PlateNumber::forDisplay($event->plate_number) }}</button>
                    </td>
                    <td class="border-b border-line py-2 text-ink-2">
                        {{ $event->camera?->name ?? '—' }}
                    </td>
                    <td class="border-b border-line py-2">
                        @if ($event->direction === null)
                            <span class="text-[11.5px] text-ink-muted">—</span>
                        @else
                            <span @class([
                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em]',
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
                            <span class="text-[11.5px] text-ink-muted">—</span>
                        @else
                            <span @class([
                                'text-[11.5px] tabular-nums',
                                'text-warning' => $conf < 85,
                                'text-ink-2' => $conf >= 85,
                            ])>{{ $conf }}%</span>
                        @endif
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        @if ($this->focusedPlate === null)
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="focusOnPlate('{{ $event->plate_number }}')"
                            >History</flux:button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-data-table>

        @if ($this->events->hasPages())
            <div class="mt-4">
                {{ $this->events->links() }}
            </div>
        @endif
    </x-panel>
</div>
