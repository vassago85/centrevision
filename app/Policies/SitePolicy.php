<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use App\Support\Tenancy;

class SitePolicy
{
    public function __construct(protected Tenancy $tenancy) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A shop can see the site it trades in, because that is where its own
     * numbers come from. It cannot see any other.
     */
    public function view(User $user, Site $site): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return in_array($site->getKey(), $this->tenancy->accessibleSiteIds(), true);
    }

    /**
     * Owners self-serve their own portfolio of sites. Platform admins can
     * seed sites for any tenant. Shops cannot create sites, only occupy them.
     */
    public function create(User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $user->isOwnerAdmin() && $user->can('manage site settings');
    }

    public function update(User $user, Site $site): bool
    {
        return $user->can('manage site settings') && $this->view($user, $site);
    }

    /**
     * Deleting a site cascades cameras, plate events, invoices and shop
     * assignments, which is destructive enough that only the owner of the
     * site (or a platform admin) may authorise it.
     */
    public function delete(User $user, Site $site): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $user->isOwnerAdmin()
            && $user->can('manage site settings')
            && $this->view($user, $site);
    }

    public function manageCameras(User $user, Site $site): bool
    {
        return $user->can('manage cameras') && $this->view($user, $site);
    }

    public function manageShops(User $user, Site $site): bool
    {
        return $user->can('manage shops') && $this->view($user, $site);
    }

    public function viewSecurity(User $user, Site $site): bool
    {
        return $user->can('view security alerts') && $this->view($user, $site);
    }

    /**
     * Add / remove watchlist plates. Split from viewSecurity so a Security
     * Operator can curate the list without also being handed every other
     * owner-level ability.
     */
    public function manageWatchlist(User $user, Site $site): bool
    {
        return $user->can('manage watchlist') && $this->view($user, $site);
    }
}
