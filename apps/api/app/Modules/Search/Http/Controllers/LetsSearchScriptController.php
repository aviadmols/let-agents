<?php

namespace App\Modules\Search\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Let’s Search drawer, one file with the engine and its stylesheets inside, built by
 * resources/search/lets-search/build.js and committed.
 *
 * GET /api/v1/search/lets-search.js (and the old /api/v1/search/let-agents-search.js) is the address every store loads,
 * from the theme extension and the WordPress plugin: a loader of a few hundred bytes, fresh for five minutes, that adds
 * the drawer itself from an address named by its content (lets-search.{hash}.js), kept by the browser for a year. A
 * deploy still reaches every store within minutes (the loader names the new file), and a shopper downloads the drawer
 * once, not on every visit (App Store review, 19.11: 238 KB again every five minutes).
 */
final class LetsSearchScriptController
{
    public const PATH = __DIR__.'/../../resources/search/lets-search.js';

    /** The loader's freshness: the drawer's own file is kept for a year, so a deploy waits on this alone. */
    private const LOADER_SECONDS = 300;

    private const YEAR = 31536000;

    /** @var array{0: int, 1: string}|null the file's modification time and the hash of its content */
    private static ?array $memo = null;

    /** The drawer's content hash, read again only when the file changes (one worker serves many requests). */
    public static function hash(): string
    {
        clearstatcache(true, self::PATH);
        $time = (int) @filemtime(self::PATH);
        if (self::$memo === null || self::$memo[0] !== $time) {
            self::$memo = [$time, substr(hash('sha256', (string) file_get_contents(self::PATH)), 0, 12)];
        }

        return self::$memo[1];
    }

    /** The loader: adds lets-search.{hash}.js beside itself, with the same query, so the drawer reads its address as before. */
    public function __invoke(Request $request): Response
    {
        $hash = self::hash();
        $fallback = route('api.search.lets-search.file', ['hash' => $hash]);
        $loader = '(function(){var d=document,s=d.currentScript,u=s&&s.src?String(s.src):"",i=u.indexOf("?"),q=i<0?"":u.slice(i),'
            .'e=d.createElement("script");e.src=u?u.slice(0,i<0?u.length:i).replace(/[^\/]*$/,"lets-search.'.$hash.'.js")+q:'.json_encode($fallback).';'
            .'e.async=false;(d.head||d.documentElement).appendChild(e);})();';

        return $this->script($request, $loader, 'public, max-age='.self::LOADER_SECONDS.', stale-while-revalidate=600');
    }

    /** The drawer itself: kept for a year under its own hash; an older hash (a loader still cached) gets today's file, briefly. */
    public function file(Request $request, string $hash): Response
    {
        $current = $hash === self::hash();

        return $this->script($request, (string) file_get_contents(self::PATH),
            $current ? 'public, max-age='.self::YEAR.', immutable' : 'public, max-age='.self::LOADER_SECONDS);
    }

    private function script(Request $request, string $body, string $cache): Response
    {
        $etag = '"'.substr(hash('sha256', $body), 0, 20).'"';
        $headers = [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => $cache,
            'ETag' => $etag,
            // The store's own speed tools may see how long it took.
            'Timing-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers);
    }
}
