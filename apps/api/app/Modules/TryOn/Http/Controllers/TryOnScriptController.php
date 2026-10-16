<?php

namespace App\Modules\TryOn\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/try-on/let-agents-tryon.js — retired: Try-On was removed from the storefront (the
 * owner's word). A store still on an old plugin or widget may ask for this address, so it answers
 * with a script that does nothing, cached as before. Nothing else of Try-On is served any more.
 */
final class TryOnScriptController
{
    public const SCRIPT = "/* Lets Search: Try-On was removed */\n";

    public function __invoke(Request $request): Response
    {
        $etag = '"'.substr(hash('sha256', self::SCRIPT), 0, 20).'"';
        $headers = ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300', 'ETag' => $etag, 'X-Content-Type-Options' => 'nosniff'];

        return $request->header('If-None-Match') === $etag ? response('', 304, $headers) : response(self::SCRIPT, 200, $headers);
    }
}
