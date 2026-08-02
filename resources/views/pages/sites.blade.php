<?php

use App\Enums\CameraRole;
use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sites')] class extends Component
{
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

    #[Computed]
    public function sites(): Collection
    {
        $tenancy = app(Tenancy::class);
        $sites = $tenancy->sites();
        $siteIds = $sites->modelKeys();

        if ($sites->isEmpty()) {
            return collect();
        }

        $cameraCounts = Camera::query()
            ->whereIn('site_id', $siteIds)
            ->toBase()
            ->selectRaw('site_id, COUNT(*) AS total, COUNT(*) FILTER (WHERE is_active) AS active')
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        $shopCounts = Organization::query()
            ->where('type', OrganizationType::Shop)
            ->whereIn('parent_site_id', $siteIds)
            ->toBase()
            ->selectRaw('parent_site_id AS site_id, COUNT(*) AS total, COUNT(*) FILTER (WHERE EXISTS (
                SELECT 1 FROM shop_subscriptions ss
                WHERE ss.organization_id = organizations.id AND ss.status = ?
            )) AS paying', [SubscriptionStatus::Active->value])
            ->groupBy('parent_site_id')
            ->get()
            ->keyBy('site_id');

        // Freshness signal for the "last event" line — the most recent capture
        // across any camera at each site, taken from the denormalised column
        // the ingestion pipeline maintains.
        $lastEvent = Camera::query()
            ->whereIn('site_id', $siteIds)
            ->toBase()
            ->selectRaw('site_id, MAX(last_event_at) AS last_event_at')
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        return $sites->map(function (Site $site) use ($cameraCounts, $shopCounts, $lastEvent) {
            $cam = $cameraCounts->get($site->id);
            $shop = $shopCounts->get($site->id);
            $last = $lastEvent->get($site->id);

            return (object) [
                'site' => $site,
                'cameras_total' => (int) ($cam->total ?? 0),
                'cameras_active' => (int) ($cam->active ?? 0),
                'entrances' => Camera::query()
                    ->where('site_id', $site->id)
                    ->whereIn('role', [CameraRole::Entrance, CameraRole::Both])
                    ->count(),
                'shops_total' => (int) ($shop->total ?? 0),
                'shops_paying' => (int) ($shop->paying ?? 0),
                'last_event_at' => $last?->last_event_at ? \Illuminate\Support\Facades\Date::parse($last->last_event_at) : null,
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
    <x-page-header title="Sites" subtitle="Every site this account owns, in one list. Click Focus to scope the rest of the app to that site.">
        <x-slot name="actions">
            <flux:button size="sm" variant="ghost" wire:click="viewAll">View all sites</flux:button>
        </x-slot>
    </x-page-header>

    @if ($this->sites->isEmpty())
        <x-placeholder>No sites configured yet.</x-placeholder>
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
                        <div>
                            <h2 class="text-base font-semibold text-ink">{{ $row->site->name }}</h2>
                            @if ($row->site->address)
                                <p class="text-[13px] text-ink-2">{{ $row->site->address }}</p>
                            @endif
                        </div>

                        @if ($isCurrent)
                            <span class="rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-accent">In focus</span>
                        @endif
                    </header>

                    <dl class="grid grid-cols-3 gap-3 text-[13px]">
                        <div>
                            <dt class="text-[11px] uppercase tracking-[0.14em] text-ink-muted">Cameras</dt>
                            <dd class="mt-1 font-semibold text-ink">
                                {{ $row->cameras_active }}<span class="text-ink-muted"> / {{ $row->cameras_total }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-[0.14em] text-ink-muted">Entrances</dt>
                            <dd class="mt-1 font-semibold text-ink">{{ $row->entrances }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-[0.14em] text-ink-muted">Sub-accounts</dt>
                            <dd class="mt-1 font-semibold text-ink">
                                {{ $row->shops_paying }}<span class="text-ink-muted"> / {{ $row->shops_total }}</span>
                            </dd>
                        </div>
                    </dl>

                    <footer class="flex items-center justify-between gap-3 border-t border-line pt-3">
                        <p class="text-[12px] text-ink-muted">
                            @if ($row->last_event_at)
                                Last event {{ $row->last_event_at->diffForHumans() }}
                            @else
                                No traffic recorded
                            @endif
                        </p>

                        <div class="flex items-center gap-2">
                            @unless ($isCurrent)
                                <flux:button size="xs" variant="primary" wire:click="focus({{ $row->site->id }})">Focus here</flux:button>
                            @endunless
                            <flux:button size="xs" variant="ghost" :href="route('cameras')" wire:navigate>Cameras</flux:button>
                        </div>
                    </footer>
                </article>
            @endforeach
        </div>
    @endif
</div>
