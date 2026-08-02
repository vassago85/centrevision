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

    public function update(User $user, Site $site): bool
    {
        return $user->can('manage site settings') && $this->view($user, $site);
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
}
