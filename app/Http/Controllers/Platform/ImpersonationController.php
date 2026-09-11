<?php

namespace App\Http\Controllers\Platform;

use App\Models\Organization;
use App\Support\Navigation;
use App\Support\Platform\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Start / stop platform-admin impersonation of an owner.
 *
 * Kept as plain controller endpoints (not Livewire actions) so the flow
 * survives full-page redirects: Livewire actions would rewrite the session
 * and hand back an SPA response, but the app layout needs a hard reload
 * to render the impersonation banner and repaint the sidebar with the
 * impersonated user's tab list.
 */
class ImpersonationController
{
    public function __construct(protected Impersonation $impersonation) {}

    public function start(Request $request, Organization $organization): RedirectResponse
    {
        $admin = $request->user();

        if ($admin === null || ! $admin->isPlatformAdmin()) {
            abort(403);
        }

        $target = $this->impersonation->start($admin, $organization);

        if ($target === null) {
            return redirect()
                ->route('platform.owners')
                ->with('status', "{$organization->name} has no owner admin to view as.");
        }

        return redirect()
            ->route(Navigation::homeRouteFor($target))
            ->with('status', "Viewing as {$target->name}.");
    }

    public function stop(Request $request): RedirectResponse
    {
        $restored = $this->impersonation->stop();

        if ($restored === null) {
            return redirect()->route('login');
        }

        return redirect()
            ->route(Navigation::homeRouteFor($restored))
            ->with('status', 'Stopped viewing as tenant.');
    }
}
