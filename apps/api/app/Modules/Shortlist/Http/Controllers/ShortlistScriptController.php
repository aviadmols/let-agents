<?php

namespace App\Modules\Shortlist\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/shortlist/let-agents-shortlist.js — retired. The heart, the compare box and the saved
 * list live in the search drawer now (Search's lets-search.js, its "saved" tab). A store still on an
 * old plugin asks for this address, so it answers with a script that does nothing, cached as before.
 */
final class ShortlistScriptController
{
    public const SCRIPT = "/* Lets Search: the saved list lives in the search drawer now */\n";

    public function __invoke(Request $request): Response
    {
        $etag = '"'.substr(hash('sha256', self::SCRIPT), 0, 20).'"';

        $headers = [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response(self::SCRIPT, 200, $headers);
    }
}
