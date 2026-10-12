<?php

namespace App\Modules\Shortlist\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/shortlist/let-agents-shortlist.js — the compare and wishlist buttons. The search
 * script loads it only in a shop that turned either on. Served by the app, so every store has a new
 * version within minutes of a deploy.
 */
final class ShortlistScriptController
{
    public const PATH = __DIR__.'/../../resources/shortlist/let-agents-shortlist.js';

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
