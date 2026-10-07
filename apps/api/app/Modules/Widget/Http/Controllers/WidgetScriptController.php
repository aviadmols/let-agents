<?php

namespace App\Modules\Widget\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/widget/let-agents.js — the storefront widget. Served by the app rather than as a static
 * file so every store gets a new version within minutes of a deploy, without a plugin update.
 */
final class WidgetScriptController
{
    public const PATH = __DIR__.'/../../resources/widget/let-agents.js';

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
