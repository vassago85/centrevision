<?php

use App\Enums\BaseTier;
use App\Enums\CameraRole;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\Site;
use App\Models\Visit;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sites')] class extends Component
{
    /** Null while adding, otherwise the id of the site being edited. */
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $name = '';

    public string $address = '';

    /**
     * GPS coordinates. Optional in v1 — a site without them still works
     * everywhere; the weather/holiday chart markers just don't appear until
     * they are set. Strings on the form so an empty field is easy to detect;
     * they're cast when saved.
     */
    public string $latitude = '';

    public string $longitude = '';

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Google Maps' right-click context menu copies coordinates as a single
     * "lat, lng" string (e.g. "-26.17111, 28.32843"). If a comma appears in
     * the latitude field we treat that as a pasted pair and split it into
     * both fields, so the owner doesn't have to edit the string by hand.
     *
     * A plain single number is left alone. A malformed pair (non-numeric
     * halves) is also left alone so validation surfaces the real problem
     * rather than us silently swallowing the paste.
     */
    public function updatedLatitude(): void
    {
        if (! str_contains($this->latitude, ',')) {
            return;
        }

        [$lat, $lng] = array_pad(
            array_map('trim', explode(',', $this->latitude, 2)),
            2,
            '',
        );

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return;
        }

        $this->latitude = $lat;
        $this->longitude = $lng;
    }

    /**
     * Set the site switcher's current selection and jump to the overview.
     *
     * Owners with more than one site tend to work "inside" one at a time;
     * this replaces the site-switcher dropdown with an explicit, per-site
     * button that also gives us room to expose the summary numbers.
     */
    public function focus(int $siteId): void
    {
        $tenancy = app(Tenancy::class);

        if (! in_array($siteId, $tenancy->accessibleSiteIds(), true)) {
            return;
        }

        session()->put('tenancy.site_id', $siteId);
        $this->redirect(route('overview'), navigate: true);
    }

    /**
     * Clear the site switcher so subsequent pages show every site the owner owns.
     */
    public function viewAll(): void
    {
        session()->put('tenancy.site_id', null);
        $this->redirect(route('overview'), navigate: true);
    }

    /**
     * Open the add-site form. We do this rather than using a "one big form"
     * pattern so the operator's context (the list of existing sites) stays
     * visible behind the modal.
     */
    public function add(): void
    {
        $this->authorize('create', Site::class);

        $this->reset(['editingId', 'name', 'address', 'latitude', 'longitude']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        $this->authorize('update', $site);

        $this->resetValidation();
        $this->editingId = $site->getKey();
        $this->name = $site->name;
        $this->address = (string) $site->address;
        $this->latitude = $site->latitude === null ? '' : (string) $site->latitude;
        $this->longitude = $site->longitude === null ? '' : (string) $site->longitude;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        if ($this->editingId === null) {
            $this->authorize('create', Site::class);

            $tenancy = app(Tenancy::class);
            $owner = $tenancy->organization();

            abort_if($owner === null || ! $owner->isOwner(), 403);

            $site = Site::create([
                'organization_id' => $owner->getKey(),
                'name' => trim($this->name),
                'address' => trim($this->address) ?: null,
                'latitude' => $this->latitude === '' ? null : (float) $this->latitude,
                'longitude' => $this->longitude === '' ? null : (float) $this->longitude,
                // v1 is ZA-first — every new site defaults to South Africa
                // so weather/holiday enrichment "just works" without asking
                // the owner to fill in extra fields.
                'country_code' => 'ZA',
                'timezone' => 'Africa/Johannesburg',
            ]);

            // Attach a default (metered, Active) subscription so billing has
            // a home for this site's charges from day one.
            $site->attachDefaultSubscription();

            // Drop the Tenancy site cache so any scoped query in the same
            // request — including this component's next render — sees the new
            // site rather than the pre-save list.
            $tenancy->refreshSites();

            Flux::toast(variant: 'success', text: '"'.$site->name.'" added. Add cameras to start metering.');
        } else {
            $site = Site::findOrFail($this->editingId);
            $this->authorize('update', $site);

            $site->update([
                'name' => trim($this->name),
                'address' => trim($this->address) ?: null,
                'latitude' => $this->latitude === '' ? null : (float) $this->latitude,
                'longitude' => $this->longitude === '' ? null : (float) $this->longitude,
            ]);

            app(Tenancy::class)->refreshSites();

            Flux::toast(variant: 'success', text: 'Site updated.');
        }

        // Fresh render pulls the new/updated site into the cards below and
        // the site switcher in the sidebar.
        unset($this->sites);
        $this->showForm = false;
    }

    #[Computed]
    public function sites(): Collection
    {
        $tenancy = app(Tenancy::class);
        $sites = $tenancy->sites();
        $siteIds = $sites->modelKeys();

        if ($sites->isEmpty()) {
            return collect();
        }

        // One query per fact, keyed by site id — the site card wants a
        // business snapshot (cameras online, visits today, on site now)
        // rather than a per-site N+1 walk.
        $cameraCounts = Camera::query()
            ->whereIn('site_id', $siteIds)
            ->toBase()
            ->selectRaw('site_id, COUNT(*) AS total, COUNT(*) FILTER (WHERE is_active) AS active')
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        // "Camera online" for the card mirrors what the Cameras page shows
        // in its status strip: active and reachable within the stale
        // window. Reachability lives across three timestamp columns, so
        // OR them all in a single scan.
        $staleWindow = now()->subMinutes((int) config('trafficflow.camera_stale_after_minutes'));
        $cameraOnline = Camera::query()
            ->whereIn('site_id', $siteIds)
            ->where('is_active', true)
            ->where(function ($q) use ($staleWindow) {
                $q->where('last_event_at', '>=', $staleWindow)
                    ->orWhere('last_probe_ok_at', '>=', $staleWindow)
                    ->orWhere('webhook_last_seen_at', '>=', $staleWindow);
            })
            ->toBase()
            ->selectRaw('site_id, COUNT(*) AS online')
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        // Exit-tracking sites can report on-site now honestly; entry-only
        // sites can't, and we don't want the card to lie about it.
        $exitTrackingSiteIds = Camera::query()
            ->whereIn('site_id', $siteIds)
            ->whereIn('role', [CameraRole::Exit, CameraRole::Both])
            ->distinct()
            ->pluck('site_id')
            ->flip();

        // Freshness signal for the "last read" line.
        $lastEvent = Camera::query()
            ->whereIn('site_id', $siteIds)
            ->toBase()
            ->selectRaw('site_id, MAX(last_event_at) AS last_event_at')
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        // Visits today + on-site now, batched per site. Kept off the
        // TrafficAnalytics service because that layer takes a DateRange
        // + implicit tenant scope; the sites screen needs an explicit
        // multi-site rollup instead.
        $todayStart = now()->startOfDay();
        $visitsToday = Visit::query()
            ->withoutGlobalScope(\App\Models\Scopes\SiteScope::class)
            ->excludingRecurring()
            ->whereIn('site_id', $siteIds)
            ->where('entered_at', '>=', $todayStart)
            ->toBase()
            ->selectRaw('site_id, COUNT(*) AS visits')
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        $onSite = Visit::query()
            ->withoutGlobalScope(\App\Models\Scopes\SiteScope::class)
            ->excludingRecurring()
            ->whereIn('site_id', $siteIds)
            ->where('status', VisitStatus::Open)
            ->toBase()
            ->selectRaw('site_id, COUNT(*) AS on_site')
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        return $sites->map(function (Site $site) use (
            $cameraCounts,
            $cameraOnline,
            $exitTrackingSiteIds,
            $lastEvent,
            $visitsToday,
            $onSite,
        ) {
            $cam = $cameraCounts->get($site->id);
            $last = $lastEvent->get($site->id);
            $activeCameras = (int) ($cam->active ?? 0);
            $totalCameras = (int) ($cam->total ?? 0);
            $onlineCameras = (int) ($cameraOnline->get($site->id)->online ?? 0);
            $hasExit = $exitTrackingSiteIds->has($site->id);
            $onSiteCount = (int) ($onSite->get($site->id)->on_site ?? 0);
            $capacity = $site->parkingCapacity();

            return (object) [
                'site' => $site,
                'cameras_total' => $totalCameras,
                'cameras_active' => $activeCameras,
                'cameras_online' => $onlineCameras,
                'cameras_offline' => max(0, $activeCameras - $onlineCameras),
                'has_exit_tracking' => $hasExit,
                'visits_today' => (int) ($visitsToday->get($site->id)->visits ?? 0),
                'on_site' => $hasExit ? $onSiteCount : null,
                'capacity' => $capacity,
                'occupancy_percent' => ($hasExit && $capacity !== null && $capacity > 0)
                    ? round($onSiteCount / $capacity * 100, 1)
                    : null,
                // Metered pricing: the tier reflects the site's live camera
                // count, and moves as cameras are added or retired.
                'tier' => BaseTier::forCameraCount($activeCameras),
                'last_event_at' => $last?->last_event_at ? Date::parse($last->last_event_at) : null,
            ];
        });
    }

    #[Computed]
    public function currentSiteId(): ?int
    {
        return app(Tenancy::class)->currentSiteId();
    }
}; ?>

<div>
    <x-page-header
        title="Sites"
        subtitle="Every property this account owns. Add a site, plug in cameras, and pricing meters itself against how many cameras that site actually runs."
    >
        <x-slot name="actions">
            @unless ($this->sites->isEmpty())
                <flux:button size="sm" variant="ghost" wire:click="viewAll">View all sites</flux:button>
            @endunless
            <flux:button size="sm" variant="primary" icon="plus" wire:click="add">New site</flux:button>
        </x-slot>
    </x-page-header>

    {{-- Metering explainer — kept low-key so it doesn't shout, but visible
         enough that a new owner understands "cameras → tier → bill" before
         they add their first site. --}}
    <div class="mb-6 rounded-tf border border-line bg-surface-2 p-4 text-[13px] text-ink-2">
        <p>
            <span class="font-semibold text-ink">Metered pricing.</span>
            Each site's monthly base is set by its <em class="not-italic font-semibold text-ink">live camera count</em> at billing time —
            Starter up to 4 cameras (R{{ number_format(BaseTier::Starter->baseFee(), 0) }}),
            Standard to 8 (R{{ number_format(BaseTier::Standard->baseFee(), 0) }}),
            Large to 16 (R{{ number_format(BaseTier::Large->baseFee(), 0) }}),
            Enterprise beyond that (R{{ number_format(BaseTier::Large->baseFee(), 0) }} +
            R{{ number_format(BaseTier::ENTERPRISE_PER_CAMERA_FEE, 0) }}/extra camera).
            No caps on how many sites you can add. A site with zero cameras costs nothing.
        </p>
    </div>

    @if ($this->sites->isEmpty())
        <div class="rounded-tf border border-dashed border-line bg-surface p-10 text-center">
            <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-accent-soft text-accent">
                <flux:icon icon="building-office-2" class="size-6" />
            </div>
            <h2 class="mt-4 text-[15px] font-semibold text-ink">Add your first site</h2>
            <p class="mx-auto mt-1 max-w-md text-[13px] text-ink-2">
                A site is one property — a mall, a park, a business complex. You can add as many as you need;
                billing only charges for the ones with cameras plugged in.
            </p>
            <flux:button class="mt-5" variant="primary" icon="plus" wire:click="add">New site</flux:button>
        </div>
    @else
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($this->sites as $row)
                @php
                    $isCurrent = $this->currentSiteId === $row->site->id;
                @endphp

                <article @class([
                    'flex flex-col gap-4 rounded-tf border bg-surface p-5',
                    'border-accent shadow-[0_0_0_1px_var(--color-accent)]' => $isCurrent,
                    'border-line' => ! $isCurrent,
                ])>
                    <header class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold text-ink">{{ $row->site->name }}</h2>
                            @if ($row->site->address)
                                <p class="text-[13px] text-ink-2">{{ $row->site->address }}</p>
                            @endif
                        </div>

                        <div class="flex flex-col items-end gap-1.5">
                            @if ($isCurrent)
                                <span class="rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-accent">Current</span>
                            @endif
                            <span class="rounded-full bg-surface-2 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                                {{ $row->tier->label() }} tier
                            </span>
                        </div>
                    </header>

                    {{-- Camera status strip — a compact business signal that
                         echoes the top of the Cameras page. Green dot when
                         everything is talking; red when a device is down. --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px]">
                        @if ($row->cameras_total === 0)
                            <span class="inline-flex items-center gap-1.5 text-ink-muted">
                                <span class="size-2 rounded-full bg-surface-2 border border-line"></span>
                                No cameras yet
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5">
                                <span class="size-2 rounded-full {{ $row->cameras_offline === 0 && $row->cameras_online > 0 ? 'bg-positive' : ($row->cameras_online > 0 ? 'bg-warn' : 'bg-danger') }}"></span>
                                <span class="font-semibold text-ink">{{ $row->cameras_total }}</span>
                                <span class="text-ink-2">{{ \Illuminate\Support\Str::plural('camera', $row->cameras_total) }}</span>
                            </span>
                            <span class="text-ink-muted">·</span>
                            <span class="text-ink-2">
                                <span class="font-semibold {{ $row->cameras_online === $row->cameras_active ? 'text-positive' : 'text-warn' }}">{{ $row->cameras_online }}</span>
                                / {{ $row->cameras_active }} online
                            </span>
                        @endif
                    </div>

                    <dl class="grid grid-cols-2 gap-3 text-[13px]">
                        <div>
                            <dt class="text-[11px] uppercase tracking-[0.14em] text-ink-muted">Visits today</dt>
                            <dd class="mt-1 text-[17px] font-semibold text-ink tabular-nums">{{ number_format($row->visits_today) }}</dd>
                        </div>
                        @if ($row->has_exit_tracking)
                            <div>
                                <dt class="text-[11px] uppercase tracking-[0.14em] text-ink-muted">On site now</dt>
                                <dd class="mt-1 text-[17px] font-semibold text-ink tabular-nums">
                                    {{ number_format($row->on_site) }}
                                    @if ($row->occupancy_percent !== null)
                                        <span class="text-[12px] font-normal text-ink-muted">
                                            · {{ rtrim(rtrim(number_format($row->occupancy_percent, 1), '0'), '.') }}%
                                        </span>
                                    @endif
                                </dd>
                            </div>
                        @else
                            <div>
                                <dt class="text-[11px] uppercase tracking-[0.14em] text-ink-muted">On site now</dt>
                                <dd class="mt-1 text-[13px] text-ink-muted">Needs an exit camera</dd>
                            </div>
                        @endif
                    </dl>

                    <footer class="flex items-center justify-between gap-3 border-t border-line pt-3">
                        <p class="text-[12px] text-ink-muted">
                            @if ($row->last_event_at)
                                Last read {{ $row->last_event_at->diffForHumans() }}
                            @else
                                No traffic recorded
                            @endif
                        </p>

                        <div class="flex items-center gap-2">
                            @unless ($isCurrent)
                                <flux:button size="xs" variant="primary" wire:click="focus({{ $row->site->id }})">Open site</flux:button>
                            @endunless
                            <flux:button size="xs" variant="ghost" wire:click="edit({{ $row->site->id }})">Edit</flux:button>
                            <flux:button size="xs" variant="ghost" :href="route('cameras')" wire:navigate>Cameras</flux:button>
                        </div>
                    </footer>
                </article>
            @endforeach
        </div>
    @endif

    {{-- ── Add / edit site modal ───────────────────────────────────────
         The same form covers name, address, and coordinates. Cameras,
         subscriptions and shops are all set up on their own tabs. --}}
    <flux:modal wire:model.self="showForm" class="md:w-[28rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit site' : 'Add site' }}</flux:heading>

            <flux:input
                wire:model="name"
                label="Name"
                placeholder="e.g. Menlyn Corner"
                autofocus
            />

            <flux:input
                wire:model="address"
                label="Address (optional)"
                placeholder="14 Atterbury Rd, Pretoria"
            />

            {{-- Coordinates unlock the weather + holiday markers on the daily
                 chart. They're optional: a site without them still shows every
                 KPI, just no weather overlay. Latitude uses .blur so that
                 pasting a "lat, lng" pair from Google Maps triggers the
                 updatedLatitude splitter the moment focus leaves the field. --}}
            <div class="space-y-1">
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input
                        wire:model.blur="latitude"
                        label="Latitude (optional)"
                        placeholder="-25.7847 or -25.7847, 28.2769"
                        inputmode="decimal"
                    />
                    <flux:input
                        wire:model="longitude"
                        label="Longitude (optional)"
                        placeholder="28.2769"
                        inputmode="decimal"
                    />
                </div>
                <p class="text-[11px] text-ink-2">
                    Tip: right-click the spot on Google Maps and click the coordinates
                    to copy. Paste the whole pair into Latitude — we'll split it into
                    both fields for you.
                </p>
            </div>

            @if (! $editingId)
                <div class="rounded-tf border border-line bg-surface-2 p-3 text-[12px] text-ink-2">
                    A Starter subscription (R{{ number_format(BaseTier::Starter->baseFee(), 0) }}/month, up to 4 cameras)
                    will attach automatically. You'll only be billed once you add a camera —
                    a site with zero cameras costs nothing. As you add cameras the tier
                    auto-adjusts on your next invoice.
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" @click="$dispatch('close')">Cancel</flux:button>
                <flux:button variant="primary" type="submit">{{ $editingId ? 'Save' : 'Add site' }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
