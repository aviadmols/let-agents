<?php

namespace App\Modules\Search\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/search/rega-search.js — the storefront search. Served by the app, like the widget,
 * so every store runs the newest version minutes after a deploy, without a plugin update.
 */
final class SearchScriptController
{
    public const PATH = __DIR__.'/../../resources/search/rega-search.js';

    public function __invoke(Request $request): Response
    {
        $script = (string) file_get_contents(self::PATH);
        $etag = '"'.substr(hash('sha256', $script), 0, 20).'"';

        $headers = [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response($script, 200, $headers);
    }
}
