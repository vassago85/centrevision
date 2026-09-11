<?php

namespace App\Support\Platform;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a platform admin browse the app as one of their tenants.
 *
 * A platform login has no organization and no site, so `/overview` and the
 * rest of the tenant surface 403 for them. When an admin picks "View as"
 * on an owner, we log in the owner's admin user for the current session
 * and stash the real admin id under {@see self::SESSION_KEY}, which the
 * banner + policies read to give the session an obvious "you are pretending"
 * state and a one-click way back.
 *
 * The impersonated user is a real Auth::login — no shim in every controller
 * — but side-effects that would silently mutate the tenant (like stamping
 * `alerts_last_seen_at`) are guarded by {@see self::isActive()} so a curious
 * admin does not clear the tenant's security bell just by looking.
 */
class Impersonation
{
    public const SESSION_KEY = 'platform.impersonator_id';

    public function __construct(protected Session $session) {}

    public function isActive(): bool
    {
        return $this->session->has(self::SESSION_KEY);
    }

    public function impersonatorId(): ?int
    {
        $value = $this->session->get(self::SESSION_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    public function impersonator(): ?User
    {
        $id = $this->impersonatorId();

        return $id === null ? null : User::query()->find($id);
    }

    /**
     * Start impersonating the owner-admin sitting under this organization.
     * Returns the impersonated user, or null when the organization has no
     * owner-admin to log in as (unapproved tenant, or one where the initial
     * user was deleted).
     */
    public function start(User $admin, Organization $organization): ?User
    {
        if (! $admin->isPlatformAdmin()) {
            return null;
        }

        if ($this->isActive()) {
            // Already impersonating: stop the current session first so the
            // stored impersonator id is always the real platform admin, not
            // a chain of tenants.
            $this->stop();
        }

        $target = User::query()
            ->where('organization_id', $organization->getKey())
            ->where('role', UserRole::OwnerAdmin)
            ->orderBy('id')
            ->first();

        if ($target === null || $target->is($admin)) {
            return null;
        }

        $this->session->put(self::SESSION_KEY, $admin->getKey());

        Auth::login($target);

        return $target;
    }

    /**
     * Stop impersonating and log the real platform admin back in.
     * Returns the restored user, or null if nothing was impersonated.
     */
    public function stop(): ?User
    {
        $realId = $this->impersonatorId();

        $this->session->forget(self::SESSION_KEY);

        if ($realId === null) {
            return null;
        }

        $real = User::query()->find($realId);

        if ($real === null) {
            Auth::logout();

            return null;
        }

        Auth::login($real);

        return $real;
    }
}
