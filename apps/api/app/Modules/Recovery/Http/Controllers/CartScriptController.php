<?php

namespace App\Modules\Recovery\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/cart/let-agents-cart.js — the popup that asks for an email before payment. Served
 * by the app, like the widget and the search, so stores run the newest version without an update.
 */
final class CartScriptController
{
    public const PATH = __DIR__.'/../../resources/cart/let-agents-cart.js';

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
