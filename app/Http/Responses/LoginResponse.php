<?php

namespace App\Http\Responses;

use App\Support\Navigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Sends each user to the right landing page after login.
 *
 * The default Fortify response hard-codes config('fortify.home'), which lands
 * every role on /overview. Platform admins have no organization and no site,
 * so /overview 403s and they have to hand-edit the URL to reach /platform.
 * Deferring to Navigation::homeRouteFor keeps the routing logic in one place —
 * the topbar brand link, the root redirect and the post-login target all
 * agree on where the user belongs.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->intended(
            route(Navigation::homeRouteFor($request->user()))
        );
    }
}
