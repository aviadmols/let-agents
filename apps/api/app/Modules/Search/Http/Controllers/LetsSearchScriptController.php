<?php

namespace App\Modules\Search\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/search/lets-search.js — the Lets Search drawer, one file with the engine and its
 * stylesheets inside. Built by resources/search/lets-search/build.js and committed; served from
 * disk, so every store runs the newest version minutes after a deploy. The old address,
 * /api/v1/search/let-agents-search.js, serves the same file.
 */
final class LetsSearchScriptController
{
    public const PATH = __DIR__.'/../../resources/search/lets-search.js';

    public function __invoke(Request $request): Response
    {
        $script = (string) file_get_contents(self::PATH);
        $etag = '"'.substr(hash('sha256', $script), 0, 20).'"';

        $headers = [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Five minutes fresh; for ten more the browser uses what it has at once and checks in the background.
            // Later it asks first (a 304 by the ETag when unchanged): a day of stale copies once kept a fix away
            // from the store team who had seen the bug.
            'Cache-Control' => 'public, max-age=300, stale-while-revalidate=600',
            'ETag' => $etag,
            // The store's own speed tools may see how long it took.
            'Timing-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response($script, 200, $headers);
    }
}
