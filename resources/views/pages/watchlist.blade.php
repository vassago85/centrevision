<?php

use App\Enums\WatchlistKind;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\WatchlistPlate;
use App\Support\PlateNumber;
use App\Support\Tenancy;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Watchlist')] class extends Component
{
    public bool $showForm = false;

    public ?int $siteId = null;

    public string $plateNumber = '';

    public string $kind = 'watch';

    public string $reason = '';

    public ?string $expiresAt = null;

    /** Set when editing an existing row rather than adding one. */
    public ?int $editingId = null;

    public function mount(): void
    {
        $this->siteId = app(Tenancy::class)->currentSiteId() ?? app(Tenancy::class)->sites()->first()?->id;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'siteId' => ['required', 'integer', Rule::in(app(Tenancy::class)->accessibleSiteIds())],
            'plateNumber' => ['required', 'string', 'max:16'],
            'kind' => ['required', Rule::in(array_map(fn ($k) => $k->value, WatchlistKind::cases()))],
            'reason' => ['nullable', 'string', 'max:255'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        WatchlistPlate::updateOrCreate(
            ['site_id' => $data['siteId'], 'plate_number' => PlateNumber::normalise($data['plateNumber'])],
            [
                'kind' => WatchlistKind::from($data['kind']),
                'reason' => $data['reason'] ?: null,
                'expires_at' => $data['expiresAt'] ?: null,
                'added_by_user_id' => auth()->id(),
            ],
        );

        $this->reset(['plateNumber', 'reason', 'expiresAt', 'editingId']);
        $this->showForm = false;
        unset($this->entries);

        Flux::toast(variant: 'success', text: 'Watchlist updated.');
    }

    public function edit(int $id): void
    {
        $entry = WatchlistPlate::query()->findOrFail($id);

        $this->authorize('viewSecurity', $entry->site);

        $this->editingId = $entry->id;
        $this->siteId = $entry->site_id;
        $this->plateNumber = $entry->plate_number;
        $this->kind = $entry->kind->value;
        $this->reason = (string) $entry->reason;
        $this->expiresAt = $entry->expires_at?->format('Y-m-d\TH:i');
        $this->showForm = true;
    }

    public function remove(int $id): void
    {
        $entry = WatchlistPlate::query()->findOrFail($id);

        $this->authorize('viewSecurity', $entry->site);
        $entry->delete();

        unset($this->entries);

        Flux::toast(variant: 'success', text: 'Removed from watchlist.');
    }

    #[Computed]
    public function entries(): Collection
    {
        return WatchlistPlate::query()
            ->with('site', 'addedBy')
            ->orderByRaw("CASE kind WHEN 'block' THEN 0 WHEN 'watch' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Recent hits for each watchlist entry, computed on-the-fly against the
     * still-retained plate_events. Once a plate falls out of the retention
     * window, its hit history goes with it — this is deliberate: watchlist
     * management is about what to do next, not archived surveillance.
     *
     * @return Collection<int, object>
     */
    #[Computed]
    public function recentHits(): Collection
    {
        $entries = $this->entries;

        if ($entries->isEmpty()) {
            return collect();
        }

        // plate_events links to a site through its camera, so the join needs
        // one extra hop. Kept as a single query so hit counts scale with the
        // watchlist size, not with the number of events.
        return PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->join('cameras', 'cameras.id', '=', 'plate_events.camera_id')
            ->join('watchlist_plates', function ($join) {
                $join->on('plate_events.plate_number', '=', 'watchlist_plates.plate_number')
                    ->on('cameras.site_id', '=', 'watchlist_plates.site_id');
            })
            ->whereIn('watchlist_plates.site_id', app(Tenancy::class)->scopeSiteIds())
            ->where('plate_events.captured_at', '>=', now()->subDays(30))
            ->toBase()
            ->selectRaw('watchlist_plates.id AS watchlist_plate_id, COUNT(*) AS hits_30d, MAX(plate_events.captured_at) AS last_seen_at')
            ->groupBy('watchlist_plates.id')
            ->get()
            ->keyBy('watchlist_plate_id');
    }

    /** @return array<int, string> */
    public function kindOptions(): array
    {
        return collect(WatchlistKind::cases())
            ->mapWithKeys(fn ($k) => [$k->value => $k->label()])
            ->all();
    }
}; ?>

<div wire:poll.60s>
    <x-page-header title="Watchlist" subtitle="Plates you want to hear about the moment they arrive.">
        <x-slot:actions>
            <flux:button size="sm" variant="primary" wire:click="$set('showForm', true)">Add plate</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($showForm)
        <x-panel heading="{{ $editingId ? 'Update watchlist entry' : 'Add to watchlist' }}">
            <form wire:submit="save" class="grid gap-4 rounded-tf border border-line bg-surface p-5 md:grid-cols-2">
                <flux:select wire:model="siteId" label="Site">
                    @foreach (app(App\Support\Tenancy::class)->sites() as $s)
                        <flux:select.option :value="$s->id">{{ $s->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="kind" label="Kind">
                    @foreach ($this->kindOptions() as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="plateNumber" label="Registration" placeholder="ABC 123 GP" />
                <flux:input wire:model="expiresAt" label="Expires" type="datetime-local" description="Optional. Blank = never expires." />

                <flux:input wire:model="reason" label="Reason" class="md:col-span-2" placeholder="Why is this plate on the list?" />

                <div class="flex items-center gap-2 md:col-span-2">
                    <flux:button size="sm" variant="primary" type="submit">{{ $editingId ? 'Save changes' : 'Add' }}</flux:button>
                    <flux:button size="sm" variant="ghost" type="button" wire:click="$set('showForm', false)">Cancel</flux:button>
                </div>
            </form>
        </x-panel>
    @endif

    <div class="mb-7 grid grid-cols-3 gap-3 max-sm:grid-cols-1">
        @php($counts = $this->entries->groupBy(fn ($e) => $e->kind->value)->map->count())
        <x-metric label="Blocked" :value="(int) ($counts['block'] ?? 0)" variant="danger" />
        <x-metric label="Watch" :value="(int) ($counts['watch'] ?? 0)" variant="warn" />
        <x-metric label="VIP" :value="(int) ($counts['vip'] ?? 0)" variant="positive" />
    </div>

    @foreach ([WatchlistKind::Block, WatchlistKind::Watch, WatchlistKind::Vip] as $kind)
        @php($rowsForKind = $this->entries->where('kind', $kind))

        <x-panel :heading="$kind->label().' ('.$rowsForKind->count().')'">
            <x-data-table
                :headers="['Plate', 'Site', 'Reason', ['label' => 'Hits · 30d', 'align' => 'right'], 'Last seen', 'Expires', ['label' => '', 'align' => 'right']]"
                :is-empty="$rowsForKind->isEmpty()"
                :empty="'No '.strtolower($kind->label()).' entries.'"
            >
                @foreach ($rowsForKind as $entry)
                    @php($hits = $this->recentHits->get($entry->id))
                    <tr>
                        <td class="border-b border-line py-2"><x-plate :number="$entry->plate_number" /></td>
                        <td class="border-b border-line py-2 text-ink-2">{{ $entry->site->name }}</td>
                        <td class="border-b border-line py-2 text-ink-2">{{ $entry->reason ?: '—' }}</td>
                        <td class="border-b border-line py-2 text-right font-semibold {{ $hits ? 'text-'.$kind->tone() : 'text-ink-muted' }}">
                            {{ $hits?->hits_30d ?? '—' }}
                        </td>
                        <td class="border-b border-line py-2 text-ink-2">
                            {{ $hits && $hits->last_seen_at ? \Illuminate\Support\Facades\Date::parse($hits->last_seen_at)->diffForHumans() : '—' }}
                        </td>
                        <td class="border-b border-line py-2 text-ink-2">
                            {{ $entry->expires_at?->format('j M Y') ?? 'Never' }}
                        </td>
                        <td class="border-b border-line py-2 text-right">
                            <flux:button size="xs" variant="ghost" wire:click="edit({{ $entry->id }})">Edit</flux:button>
                            <flux:button
                                size="xs"
                                variant="danger"
                                wire:click="remove({{ $entry->id }})"
                                wire:confirm="Remove {{ $entry->plate_number }} from the watchlist?"
                            >Remove</flux:button>
                        </td>
                    </tr>
                @endforeach
            </x-data-table>
        </x-panel>
    @endforeach
</div>
