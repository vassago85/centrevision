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
use Livewire\Attributes\Url;
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

    /**
     * Filter tab in effect. `all`, `block`, `watch`, `vip`, or `expired`.
     * Kept as a URL query string so a filtered link is shareable.
     */
    #[Url(as: 'filter', keep: true)]
    public string $filter = 'all';

    public function mount(): void
    {
        $this->siteId = app(Tenancy::class)->currentSiteId() ?? app(Tenancy::class)->sites()->first()?->id;

        if (! in_array($this->filter, ['all', 'block', 'watch', 'vip', 'expired'], true)) {
            $this->filter = 'all';
        }
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

        $site = app(Tenancy::class)->sites()->firstWhere('id', $data['siteId']);
        abort_if($site === null, 403);
        $this->authorize('manageWatchlist', $site);

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

        $this->authorize('manageWatchlist', $entry->site);

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

        $this->authorize('manageWatchlist', $entry->site);
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
     * Kind-count summary for the three headline cards at the top.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'block' => $this->entries->where('kind', WatchlistKind::Block)->count(),
            'watch' => $this->entries->where('kind', WatchlistKind::Watch)->count(),
            'vip' => $this->entries->where('kind', WatchlistKind::Vip)->count(),
        ];
    }

    /**
     * The rows that pass the current filter. Sorted the same way the base
     * `entries` query is: blocks first, then watch, then VIP; newest inside
     * each kind.
     *
     * @return Collection<int, WatchlistPlate>
     */
    #[Computed]
    public function visibleEntries(): Collection
    {
        return $this->entries->filter(function (WatchlistPlate $entry): bool {
            return match ($this->filter) {
                'block' => $entry->kind === WatchlistKind::Block,
                'watch' => $entry->kind === WatchlistKind::Watch,
                'vip' => $entry->kind === WatchlistKind::Vip,
                'expired' => $entry->expires_at !== null && $entry->expires_at->isPast(),
                default => true,
            };
        })->values();
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

    /**
     * The filter chips on the page. Order matters — the "All" chip is
     * always first, and the kind chips follow the same block → watch →
     * VIP order the entries themselves use.
     *
     * @return array<int, array{key: string, label: string, count: int}>
     */
    #[Computed]
    public function filterChips(): array
    {
        $expired = $this->entries
            ->filter(fn (WatchlistPlate $e) => $e->expires_at !== null && $e->expires_at->isPast())
            ->count();

        return [
            ['key' => 'all', 'label' => 'All', 'count' => $this->entries->count()],
            ['key' => 'block', 'label' => 'Blocked', 'count' => $this->counts['block']],
            ['key' => 'watch', 'label' => 'Watch', 'count' => $this->counts['watch']],
            ['key' => 'vip', 'label' => 'VIP', 'count' => $this->counts['vip']],
            ['key' => 'expired', 'label' => 'Expired', 'count' => $expired],
        ];
    }
}; ?>

{{-- 30s cadence matches the dashboard's alertCounts refresh so the bell and
     this page never disagree by more than one poll cycle. --}}
<div wire:poll.30s>
    <x-page-header title="Watchlist" subtitle="Plates you want to hear about the moment they arrive.">
        @if ($this->entries->isNotEmpty() && ! $showForm)
            <x-slot:actions>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="$set('showForm', true)" data-test="add-plate">Add plate</flux:button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($showForm)
        <x-panel-card class="mb-4" :title="$editingId ? 'Update watchlist entry' : 'Add to watchlist'">
            <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model="siteId" label="Site">
                    @foreach (app(App\Support\Tenancy::class)->sites() as $s)
                        <flux:select.option :value="$s->id">{{ $s->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select
                    wire:model="kind"
                    label="Category"
                    description="Any active entry raises a watchlist alert when the plate enters, if email alerts are on. No category opens or closes a gate."
                >
                    @foreach ($this->kindOptions() as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="plateNumber" label="Registration" placeholder="ABC 123 GP" />
                <flux:input wire:model="expiresAt" label="Expires" type="datetime-local" description="Optional. Leave blank to keep the plate on the list." />

                <flux:input wire:model="reason" label="Reason" class="md:col-span-2" placeholder="Why is this plate on the list?" />

                <div class="flex items-center gap-2 md:col-span-2">
                    <flux:button variant="primary" type="submit">{{ $editingId ? 'Save changes' : 'Add plate' }}</flux:button>
                    <flux:button variant="ghost" type="button" wire:click="$set('showForm', false)">Cancel</flux:button>
                </div>
            </form>
        </x-panel-card>
    @endif

    @if ($this->entries->isEmpty())
        @unless ($showForm)
            <x-panel-card>
                <x-empty-state title="No plates on your watchlist" icon="bell-alert">
                    Add a plate to be notified the moment it arrives at any of your sites.
                    <x-slot:action>
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="$set('showForm', true)" data-test="add-plate">Add plate</flux:button>
                    </x-slot:action>
                </x-empty-state>
            </x-panel-card>
        @endunless
    @else
        <x-panel-card padding="p-0">
            {{-- Filter chips share the same URL slot, so a link like
                 /watchlist?filter=expired lands the recipient on the
                 exact same view. --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3" role="group" aria-label="Filter by category">
                @foreach ($this->filterChips as $chip)
                    <button
                        type="button"
                        wire:click="$set('filter', '{{ $chip['key'] }}')"
                        aria-pressed="{{ $filter === $chip['key'] ? 'true' : 'false' }}"
                        @class([
                            'inline-flex min-h-9 items-center gap-2 rounded-full border px-3 text-[13px] font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent max-sm:min-h-11',
                            'border-accent bg-accent text-white' => $filter === $chip['key'],
                            'border-line bg-surface text-ink-2 hover:border-ink-muted hover:text-ink' => $filter !== $chip['key'],
                        ])
                    >
                        @if ($chip['key'] !== 'all' && $chip['key'] !== 'expired')
                            <span @class([
                                'size-2 rounded-full',
                                'bg-danger' => $chip['key'] === 'block',
                                'bg-warn' => $chip['key'] === 'watch',
                                'bg-positive' => $chip['key'] === 'vip',
                            ]) aria-hidden="true"></span>
                        @endif
                        <span>{{ $chip['label'] }}</span>
                        <span @class([
                            'tabular-nums',
                            'text-white/80' => $filter === $chip['key'],
                            'text-ink-muted' => $filter !== $chip['key'],
                        ])>{{ $chip['count'] }}</span>
                    </button>
                @endforeach
            </div>

            <div class="p-4 sm:p-5">
                <x-data-table
                    :headers="['Plate', 'Category', 'Site', 'Reason', ['label' => 'Reads · 30 days', 'align' => 'right'], 'Last seen', 'Expires', ['label' => '', 'align' => 'right']]"
                    :is-empty="$this->visibleEntries->isEmpty()"
                    empty="No plates in this category. Try a different filter."
                >
                    @foreach ($this->visibleEntries as $entry)
                        @php
                            $hits = $this->recentHits->get($entry->id);
                            $isExpired = $entry->expires_at !== null && $entry->expires_at->isPast();
                        @endphp
                        <tr wire:key="watch-{{ $entry->id }}">
                            <td class="border-b border-line py-2"><x-plate :number="$entry->plate_number" /></td>
                            <td class="border-b border-line py-2">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[12px] font-semibold',
                                    'bg-danger-soft text-danger' => $entry->kind === WatchlistKind::Block,
                                    'bg-warn-soft text-warn' => $entry->kind === WatchlistKind::Watch,
                                    'bg-positive-soft text-positive' => $entry->kind === WatchlistKind::Vip,
                                ])>{{ $entry->kind->label() }}</span>
                            </td>
                            <td class="border-b border-line py-2 text-ink-2">{{ $entry->site->name }}</td>
                            <td class="border-b border-line py-2 text-ink-2">{{ $entry->reason ?: '—' }}</td>
                            <td class="border-b border-line py-2 text-right tabular-nums {{ $hits ? 'font-semibold text-ink' : 'text-ink-muted' }}">
                                {{ $hits?->hits_30d ?? '—' }}
                            </td>
                            <td class="whitespace-nowrap border-b border-line py-2 text-ink-2">
                                {{ $hits && $hits->last_seen_at ? \Illuminate\Support\Facades\Date::parse($hits->last_seen_at)->diffForHumans() : 'Not seen' }}
                            </td>
                            <td class="whitespace-nowrap border-b border-line py-2 {{ $isExpired ? 'font-semibold text-danger' : 'text-ink-2' }}">
                                @if ($entry->expires_at)
                                    {{ $entry->expires_at->format('j M Y') }}{{ $isExpired ? ' · expired' : '' }}
                                @else
                                    Never
                                @endif
                            </td>
                            <td class="border-b border-line py-2 text-right">
                                <div class="flex items-center justify-end gap-1 whitespace-nowrap">
                                    <flux:button size="sm" variant="ghost" wire:click="edit({{ $entry->id }})">Edit</flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        class="text-danger!"
                                        wire:click="remove({{ $entry->id }})"
                                        wire:confirm="Remove {{ $entry->plate_number }} from the watchlist?"
                                    >Remove</flux:button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>

                <p class="mt-3 text-[12.5px] text-ink-muted">
                    Reads are camera detections at the plate's site in the last 30 days. Categories only change how a match is labelled and alerted; CentreVision does not control gates or barriers.
                </p>
            </div>
        </x-panel-card>
    @endif
</div>
