<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Tenancy;

/**
 * Plate-level rows — individual events, visits and tags — are visible to the
 * property owner and the platform, never to a shop.
 *
 * A shop pays for footfall analytics, not for the ability to look up who parked
 * outside. Every shop-facing figure is an aggregate.
 */
class PlateDataPolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('view plate level data');
    }

    /**
     * Owners and the platform may read a single plate row, provided it belongs
     * to a site they can reach. The site check is a second line of defence
     * behind the SiteScope global scope.
     */
    public function view(User $user, mixed $record): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->isPlatformAdmin()) {
            return true;
        }

        return in_array($this->siteIdOf($record), $this->tenancy->accessibleSiteIds(), true);
    }

    public function create(User $user): bool
    {
        return $user->isOwnerAdmin() || $user->isPlatformAdmin();
    }

    public function update(User $user, mixed $record): bool
    {
        return $this->view($user, $record) && $user->role->canManage();
    }

    public function delete(User $user, mixed $record): bool
    {
        return $this->update($user, $record);
    }

    /**
     * Plate events reach a site through their camera; everything else carries
     * the site key directly.
     */
    protected function siteIdOf(mixed $record): ?int
    {
        return $record->site_id ?? $record->camera?->site_id;
    }
}
