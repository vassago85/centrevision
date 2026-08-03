<?php

namespace App\Http\Middleware;

use App\Models\Camera;
use App\Models\Scopes\SiteScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a Hikvision camera calling our webhook.
 *
 * Cameras are configured with HTTP Basic:
 *  - username = camera id (integer, as decimal string)
 *  - password = camera->webhook_secret (48-char token)
 *
 * The camera id also appears in the URL as {camera}, so an attacker who
 * captures a request cannot re-target the credentials at a different camera.
 * The comparison itself is constant-time to avoid a timing oracle.
 *
 * We do not use Laravel's stock auth stack here because:
 *  - The "user" is a Camera, not a User.
 *  - No session is created; every request is authenticated on its own.
 *  - The unscoped Camera lookup has to bypass SiteScope, which relies on a
 *    logged-in User to figure out which sites are visible.
 */
class AuthenticateHikCamera
{
    public function handle(Request $request, Closure $next): Response
    {
        $cameraId = (int) $request->route('camera');

        if ($cameraId <= 0) {
            return $this->unauthorized();
        }

        // SiteScope needs a signed-in user to know which sites are visible; a
        // webhook has none, so we bypass the scope explicitly.
        $camera = Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->find($cameraId);

        if ($camera === null || ! $camera->is_active) {
            return $this->unauthorized();
        }

        [$user, $pass] = $this->basicCredentials($request);

        if ($user === null || $pass === null) {
            return $this->unauthorized();
        }

        // Constant-time comparison stops a timing side channel giving away the
        // secret one character at a time. The camera id is compared straight
        // because it is already public in the URL.
        if ((int) $user !== $cameraId || ! hash_equals((string) $camera->webhook_secret, $pass)) {
            return $this->unauthorized();
        }

        // Hand the resolved Camera to the controller by attribute so it does
        // not have to hit the database a second time.
        $request->attributes->set('camera', $camera);

        return $next($request);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    protected function basicCredentials(Request $request): array
    {
        // Laravel populates getUser()/getPassword() from PHP_AUTH_USER, but
        // that only works when the SAPI decoded the header (mod_php, some
        // FastCGI configs). Fall back to parsing Authorization manually so
        // this works behind Nginx + PHP-FPM too.
        if ($request->getUser() !== null) {
            return [$request->getUser(), $request->getPassword()];
        }

        $header = $request->header('Authorization');

        if (! is_string($header) || ! str_starts_with($header, 'Basic ')) {
            return [null, null];
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return [null, null];
        }

        [$user, $pass] = explode(':', $decoded, 2);

        return [$user, $pass];
    }

    protected function unauthorized(): Response
    {
        return response('', 401, [
            'WWW-Authenticate' => 'Basic realm="CentreVision Hikvision webhook"',
        ]);
    }
}
