<?php

namespace App\Modules\Connections\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel inside wp-admin keeps its sign-in: a frame on the store's site is another site to the
 * browser, which neither keeps nor sends a SameSite=Lax cookie there, so the panel the plugin opened
 * (PluginPanelController) fell back to the login page. In a frame, the session is its own cookie,
 * SameSite=None, Secure and Partitioned: kept for the store's site alone, and never touching the
 * sign-in of agents.lets.co.il opened on its own.
 *
 * A request is in the frame when the browser says so (Sec-Fetch-Dest: iframe, the frame's pages) or
 * when it carries the frame's cookie (the panel's own calls from inside it, which the browser sends
 * nowhere else). The session settings are put back after each request: under Octane they would
 * otherwise reach the next one.
 */
final class FramedSession
{
    public const SUFFIX = '_framed';

    public function handle(Request $request, Closure $next): Response
    {
        $name = config('session.cookie').self::SUFFIX;

        if ($request->headers->get('Sec-Fetch-Dest') !== 'iframe' && ! $request->cookies->has($name)) {
            return $next($request);
        }

        $before = [
            'session.cookie' => config('session.cookie'),
            'session.same_site' => config('session.same_site'),
            'session.secure' => config('session.secure'),
            'session.partitioned' => config('session.partitioned'),
        ];
        config(['session.cookie' => $name, 'session.same_site' => 'none', 'session.secure' => true, 'session.partitioned' => true]);
        // The same session object under the frame's cookie name, never a new one: under Octane the session
        // store is shared by every request and emptied at the start of each. A driver made anew here (as
        // forgetDrivers did) left that shared store apart from the one emptied, holding the last sign-in
        // for every request after it, anonymous ones too.
        $store = app('session')->driver();
        $named = $store->getName();
        $store->setName($name);

        try {
            $response = $next($request);

            // Sent to the login page inside wp-admin's frame: which cookies came (names only) and how the browser
            // framed it, to tell why the sign-in did not hold.
            if ($response->isRedirect() && str_contains((string) $response->headers->get('Location'), '/login')) {
                Log::warning('framed panel sent to login', [
                    'host' => $request->getHost(), 'path' => $request->path(), 'cookies' => array_keys($request->cookies->all()),
                    'dest' => $request->headers->get('Sec-Fetch-Dest'), 'site' => $request->headers->get('Sec-Fetch-Site'),
                    'storage' => $request->headers->get('Sec-Fetch-Storage-Access'),
                ]);
            }

            return $response;
        } finally {
            config($before);
            $store->setName($named);
        }
    }
}
