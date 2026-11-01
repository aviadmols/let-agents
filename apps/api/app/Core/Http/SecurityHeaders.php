<?php

namespace App\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every response says about itself (1.11). A page of ours is framed by our own pages only, unless a screen said
 * who else may (the Shopify and WordPress panels, the demo, the widget preview set their own frame-ancestors, and run
 * inside this one so theirs is kept). A page Shopify opens embedded is left to Shopify's own rule: its first address
 * carries the store ("shop", "host"). A type is never guessed, the address a link came from goes no further than this
 * site, and on https the browser keeps to https.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff', false);

        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        if ($request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($this->isPage($response) && ! $headers->has('Content-Security-Policy') && ! $this->openedEmbedded($request)) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self';");
        }

        return $response;
    }

    private function isPage(Response $response): bool
    {
        return str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html');
    }

    /** A Shopify admin's first load of the app: the store and its host are in the address. */
    private function openedEmbedded(Request $request): bool
    {
        return $request->query->has('shop') && ($request->query->has('host') || $request->query->has('embedded'));
    }
}
