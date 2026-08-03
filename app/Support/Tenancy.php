<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Holds the tenant context for the current request.
 *
 * Registered as a singleton and populated by EnsureTenantContext middleware.
 * When no user has been set the tenancy is dormant, which is what console
 * commands, queued jobs and seeders need: they operate across every tenant.
 */
class Tenancy
{
    protected ?User $user = null;

    protected ?int $currentSiteId = null;

    /** @var Collection<int, Site>|null */
    protected ?Collection $sites = null;

    protected bool $suppressed = false;

    protected ?int $pinnedSiteId = null;

    public function setUser(?User $user): static
    {
        $this->user = $user;
        $this->sites = null;
        $this->currentSiteId = null;

        return $this;
    }

    /**
     * Drop the cached site collection so the next call to sites() re-reads
     * from the database. Called after actions that mutate the tenant's site
     * list (adding, renaming or deleting a site) so subsequent scoped queries
     * within the same request see the change immediately instead of being
     * masked by a stale cache.
     */
    public function refreshSites(): static
    {
        $this->sites = null;

        return $this;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * Whether queries should currently be constrained to the tenant's sites.
     */
    public function isActive(): bool
    {
        if ($this->suppressed) {
            return false;
        }

        if ($this->pinnedSiteId !== null) {
            return true;
        }

        return $this->user !== null
            && ! $this->user->role->isPlatformRole();
    }

    /**
     * Run a callback scoped to one site, with or without a logged-in user.
     *
     * Jobs that produce per-site output use this so their queries are narrowed
     * the same way a request would narrow them, instead of each query having
     * to remember a where clause.
     */
    public function forSite(Site $site, callable $callback): mixed
    {
        $previous = $this->pinnedSiteId;
        $this->pinnedSiteId = $site->getKey();

        try {
            return $callback();
        } finally {
            $this->pinnedSiteId = $previous;
        }
    }

    /**
     * Run a callback with scoping switched off. Used by jobs and reporting
     * that legitimately need to reach across tenants.
     */
    public function withoutScoping(callable $callback): mixed
    {
        $previous = $this->suppressed;
        $this->suppressed = true;

        try {
            return $callback();
        } finally {
            $this->suppressed = $previous;
        }
    }

    public function organization(): ?Organization
    {
        return $this->user?->organization;
    }

    public function role(): ?UserRole
    {
        return $this->user?->role;
    }

    public function isShop(): bool
    {
        return (bool) $this->user?->role->isShopRole();
    }

    public function isOwner(): bool
    {
        return (bool) $this->user?->role->isOwnerRole();
    }

    public function isPlatform(): bool
    {
        return (bool) $this->user?->role->isPlatformRole();
    }

    /**
     * Every site this user may reach, ignoring the site switcher.
     *
     * @return Collection<int, Site>
     */
    public function sites(): Collection
    {
        if ($this->sites !== null) {
            return $this->sites;
        }

        $organization = $this->organization();

        if ($this->user === null || $organization === null) {
            return $this->sites = new Collection;
        }

        return $this->sites = $this->withoutScoping(function () use ($organization) {
            // A shop organization reaches exactly the one site it sits inside.
            if ($organization->isShop()) {
                return Site::query()
                    ->whereKey($organization->parent_site_id)
                    ->get();
            }

            return Site::query()
                ->where('organization_id', $organization->getKey())
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * @return array<int, int>
     */
    public function accessibleSiteIds(): array
    {
        return $this->sites()->modelKeys();
    }

    /**
     * The site the owner has selected in the switcher. Shops always resolve to
     * their single site. Returns null when an owner is viewing all sites.
     */
    public function currentSiteId(): ?int
    {
        if ($this->isShop()) {
            return $this->sites()->first()?->getKey();
        }

        return $this->currentSiteId;
    }

    public function currentSite(): ?Site
    {
        $id = $this->currentSiteId();

        return $id === null ? null : $this->sites()->firstWhere('id', $id);
    }

    /**
     * Select a site in the switcher. Silently ignores sites outside the
     * tenant, so a tampered request cannot widen access.
     */
    public function setCurrentSiteId(?int $siteId): static
    {
        $this->currentSiteId = $siteId !== null && in_array($siteId, $this->accessibleSiteIds(), true)
            ? $siteId
            : null;

        return $this;
    }

    /**
     * Site ids that the current view should aggregate over: the selected site
     * if one is chosen, otherwise everything the tenant can reach.
     *
     * @return array<int, int>
     */
    public function scopeSiteIds(): array
    {
        if ($this->pinnedSiteId !== null) {
            return [$this->pinnedSiteId];
        }

        $current = $this->currentSiteId();

        return $current === null ? $this->accessibleSiteIds() : [$current];
    }

    public function hasMultipleSites(): bool
    {
        return $this->sites()->count() > 1;
    }
}
