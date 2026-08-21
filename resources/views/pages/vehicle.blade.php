<?php

use App\Models\PlateNote;
use App\Models\Visit;
use App\Models\WatchlistPlate;
use App\Support\PlateNumber;
use App\Support\Tenancy;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Vehicle')] class extends Component {
    public string $plate = '';

    public string $noteBody = '';

    public function mount(string $plate): void
    {
        $normalised = PlateNumber::normalise($plate);

        abort_if($normalised === '', 404);

        $this->plate = $normalised;
    }

    #[Computed]
    public function site()
    {
        return app(Tenancy::class)->currentSite();
    }

    #[Computed]
    public function watchlistEntry(): ?WatchlistPlate
    {
        $site = $this->site;

        if ($site === null) {
            return null;
        }

        return WatchlistPlate::query()
            ->where('site_id', $site->getKey())
            ->where('plate_number', $this->plate)
            ->first();
    }

    /**
     * @return Collection<int, Visit>
     */
    #[Computed]
    public function visits(): Collection
    {
        $site = $this->site;

        if ($site === null) {
            return collect();
        }

        return Visit::query()
            ->where('site_id', $site->getKey())
            ->where('plate_number', $this->plate)
            ->with(['entryEvent.camera:id,name'])
            ->orderByDesc('entered_at')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, PlateNote>
     */
    #[Computed]
    public function notes(): Collection
    {
        $site = $this->site;

        if ($site === null) {
            return collect();
        }

        return PlateNote::query()
            ->where('site_id', $site->getKey())
            ->where('plate_number', $this->plate)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    public function addNote(): void
    {
        $site = $this->site;

        abort_if($site === null, 404);
        $this->authorize('viewSecurity', $site);

        $this->validate([
            'noteBody' => ['required', 'string', 'max:2000'],
        ]);

        PlateNote::query()->create([
            'site_id' => $site->getKey(),
            'plate_number' => $this->plate,
            'body' => trim($this->noteBody),
            'user_id' => auth()->id(),
        ]);

        $this->noteBody = '';
        unset($this->notes);

        Flux::toast(variant: 'success', text: 'Note added.');
    }
}; ?>

<div>
    <x-page-header
        title="Vehicle {{ $plate }}"
        :subtitle="(app(App\Support\Tenancy::class)->currentSite()?->name ?? 'Site').' · profile'"
    >
        <x-slot:actions>
            <flux:button size="sm" variant="ghost" :href="route('activity', ['plate' => $plate])" wire:navigate>Back to activity</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-7 grid grid-cols-3 gap-3 max-md:grid-cols-1">
        <x-metric label="Visits shown" :value="$this->visits->count()" />
        <x-metric
            label="Watchlist"
            :value="$this->watchlistEntry?->kind->label() ?? '—'"
            :variant="$this->watchlistEntry ? 'danger' : 'default'"
        />
        <x-metric label="Notes" :value="$this->notes->count()" />
    </div>

    @if ($this->watchlistEntry)
        <x-panel heading="Watchlist">
            <p class="text-[13px] text-ink-2">
                <x-badge>{{ $this->watchlistEntry->kind->label() }}</x-badge>
                @if ($this->watchlistEntry->reason)
                    — {{ $this->watchlistEntry->reason }}
                @endif
            </p>
        </x-panel>
    @endif

    <x-panel heading="Visit history">
        <x-data-table
            :headers="['Entered', 'Exited', 'Dwell', 'Camera', 'Status']"
            :is-empty="$this->visits->isEmpty()"
            empty="No visits retained for this plate."
        >
            @foreach ($this->visits as $visit)
                <tr wire:key="visit-{{ $visit->id }}">
                    <td class="border-b border-line py-2">{{ $visit->entered_at->format('Y-m-d H:i') }}</td>
                    <td class="border-b border-line py-2">{{ $visit->exited_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="border-b border-line py-2">
                        @if ($visit->dwell_minutes !== null)
                            {{ intdiv($visit->dwell_minutes, 60) }}h {{ $visit->dwell_minutes % 60 }}m
                        @else
                            —
                        @endif
                    </td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $visit->entryEvent?->camera?->name ?? '—' }}</td>
                    <td class="border-b border-line py-2"><x-badge>{{ $visit->status->label() }}</x-badge></td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>

    <x-panel heading="Notes">
        <form wire:submit="addNote" class="mb-4 flex flex-wrap items-end gap-3">
            <flux:textarea wire:model="noteBody" label="Add note" rows="2" class="min-w-80 flex-1" />
            <flux:button variant="primary" type="submit">Save note</flux:button>
        </form>

        <x-data-table
            :headers="['When', 'By', 'Note']"
            :is-empty="$this->notes->isEmpty()"
            empty="No notes yet."
        >
            @foreach ($this->notes as $note)
                <tr wire:key="note-{{ $note->id }}">
                    <td class="border-b border-line py-2 whitespace-nowrap">{{ $note->created_at->format('Y-m-d H:i') }}</td>
                    <td class="border-b border-line py-2">{{ $note->user?->name ?? '—' }}</td>
                    <td class="border-b border-line py-2">{{ $note->body }}</td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>
</div>
