<?php

namespace App\Modules\Widget\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/widget/let-agents.js — the address stores on the older plugin still print. It now
 * serves the Lets Search drawer (Search's built resources/search/lets-search.js), which reads the
 * old window.LetAgentsContext as its config and refuses to load twice, so a page that prints both
 * addresses gets one drawer. Served by the app so every store runs the newest file minutes after
 * a deploy, without a plugin update.
 */
final class WidgetScriptController
{
    /** Read from disk, not imported: the drawer is Search's file, not a class Widget may use. */
    public const PATH = __DIR__.'/../../../Search/resources/search/lets-search.js';

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
