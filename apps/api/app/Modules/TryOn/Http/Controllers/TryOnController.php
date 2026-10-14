<?php

namespace App\Modules\TryOn\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\TryOn\Actions\GatePhoto;
use App\Modules\TryOn\Actions\StartTryOn;
use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Support\Files;
use App\Modules\TryOn\Support\TryOnClasses;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The storefront's Try-On (docs/try-on.md §6). Only the photo and the try call a model, and only
 * after the shopper's own action; the rest reads what is kept. The site key and the page's origin
 * are checked as for every storefront call; the shopper is a hash salted per shop.
 *
 *   GET  /api/v1/try-on/{site}/config?product=     whether the product can be tried, on what, and where its button goes
 *   POST /api/v1/try-on/{site}/photos              photo (file), vid, consent
 *   POST /api/v1/try-on/{site}/tries               vid, photo, product, answers{}
 *   GET  /api/v1/try-on/{site}/tries/{id}?vid=     where the try is, and its picture when ready
 *   GET  /api/v1/try-on/file?p=&signature=         a kept picture, through a signed address
 */
final class TryOnController
{
    private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function config(Request $request, string $site): JsonResponse
    {
        $connection = $this->site($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $locale = in_array($request->query('locale'), ['he', 'en'], true) ? (string) $request->query('locale') : 'he';

        return app(TenantContext::class)->run($connection->shop_id, function () use ($request, $locale, $connection): JsonResponse {
            // Shoppers only where the shop turned Try-On on; the team's test anywhere (the key is checked).
            $test = self::test($request, $connection);
            $open = Features::enabled('try_on.enabled', $connection->shop_id) || $test;
            $product = $open ? CatalogProduct::query()->whereNull('removed_at')->where('external_id', (string) $request->query('product'))->first() : null;
            // The team's test also on a product the scan did not mark, or the catalog has not read yet (by the page's name).
            $page = $test && $product === null ? self::pageProduct($request, $connection) : null;
            $class = $product === null && $page === null ? null : StartTryOn::testedClass($product, $test, self::testClass($request, $connection), $page['title'] ?? null);

            return response()->json(['data' => [
                'tryable' => $class !== null,
                'class' => $class,
                'group' => $class === null ? null : TryOnClasses::group($class),
                'needs' => $class === null ? [] : TryOnClasses::SUBJECTS[$class],
                // Where the button goes on the store's page when there is no widget row: the shop's CSS selector.
                'anchor' => [
                    'selector' => trim((string) Settings::get('try_on.anchor_selector', $connection->shop_id)),
                    'position' => (string) Settings::get('try_on.anchor_position', $connection->shop_id),
                ],
                'note' => __('try_on::try_on.ai_note', [], $locale),
                'labels' => __('try_on::try_on.ui', ['days' => (string) Settings::get('try_on.retention_days', $connection->shop_id)], $locale),
            ]], 200, ['Cache-Control' => 'public, max-age=300']);
        });
    }

    public function photo(Request $request, GatePhoto $gate, string $site): JsonResponse
    {
        $connection = $this->site($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $visitor = (string) $request->input('vid');
        $file = $request->file('photo');

        if (! preg_match(self::VISITOR_PATTERN, $visitor)) {
            return response()->json(['error' => 'invalid_visitor'], 422);
        }

        if ($file === null || ! $file->isValid()) {
            return response()->json(['error' => 'no_photo'], 422);
        }

        if ($file->getSize() > (int) Settings::get('try_on.photo_max_kb') * 1024) {
            return response()->json(['error' => 'too_big'], 413);
        }

        // What the bytes are, not what the browser or the file name says they are.
        $bytes = (string) file_get_contents($file->getRealPath());

        if (! in_array((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes), self::MIMES, true)) {
            return response()->json(['error' => 'not_image'], 415);
        }

        $result = app(TenantContext::class)->run($connection->shop_id, fn (): array => $gate->handle(self::visitor($connection->shop_id, $visitor), $bytes, $request->boolean('consent')));

        return response()->json(['data' => [
            'photo' => $result['photo']?->id,
            'status' => $result['status'],
            'reject_code' => $result['reject_code'],
            'message' => $result['reject_code'] === null ? null : __('try_on::try_on.rejected.'.$result['reject_code'], [], (string) $request->input('locale', 'he')),
            'usable' => $result['usable'],
        ]], 200, ['Cache-Control' => 'no-store']);
    }

    public function start(Request $request, StartTryOn $start, string $site): JsonResponse
    {
        $connection = $this->site($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $visitor = (string) $request->input('vid');

        if (! preg_match(self::VISITOR_PATTERN, $visitor)) {
            return response()->json(['error' => 'invalid_visitor'], 422);
        }

        $locale = (string) $request->input('locale', 'he');
        $answers = is_array($request->input('answers')) ? $request->input('answers') : [];
        $result = app(TenantContext::class)->run($connection->shop_id, fn (): array => $start->handle(self::visitor($connection->shop_id, $visitor), (string) $request->input('photo'), (string) $request->input('product'), $answers,
            test: self::test($request, $connection), testClass: self::testClass($request, $connection),
            page: self::test($request, $connection) ? self::pageProduct($request, $connection) : null));

        if ($result['questions'] !== []) {
            return response()->json(['data' => ['status' => 'needs_answer', 'questions' => array_map(fn (array $q): array => [
                'key' => $q['key'],
                'question' => __('try_on::try_on.questions.'.$q['key'], [], $locale),
                'options' => array_map(fn (array $o): array => ['key' => $o['key'], 'label' => $o['label'] ?? __('try_on::try_on.options.'.$q['key'].'.'.$o['key'], [], $locale)], $q['options']),
            ], $result['questions'])]], 200, ['Cache-Control' => 'no-store']);
        }

        if ($result['problem'] !== null) {
            return response()->json(['data' => ['status' => 'refused', 'problem' => $result['problem'], 'message' => __('try_on::try_on.problems.'.$result['problem'], [], $locale)]], 200, ['Cache-Control' => 'no-store']);
        }

        return response()->json(['data' => $this->state($result['job'])], 202, ['Cache-Control' => 'no-store']);
    }

    public function show(Request $request, string $site, string $id): JsonResponse
    {
        $connection = $this->site($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $visitor = (string) $request->query('vid');
        $job = app(TenantContext::class)->run($connection->shop_id, function () use ($id, $connection, $visitor): ?TryOnJob {
            $job = TryOnJob::query()->whereKey($id)->where('visitor_hash', self::visitor($connection->shop_id, $visitor))->first();
            $job?->endIfStuck();

            return $job;
        });

        return $job === null
            ? response()->json(['error' => 'unknown_try'], 404)
            : response()->json(['data' => $this->state($job)], 200, ['Cache-Control' => 'no-store']);
    }

    /** The storefront's Try-On window, served like the other storefront scripts. */
    public function script(Request $request): Response
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/resources/tryon/let-agents-tryon.js');
        $etag = '"'.substr(hash('sha256', $script), 0, 20).'"';
        $headers = ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300', 'ETag' => $etag, 'X-Content-Type-Options' => 'nosniff'];

        return $request->header('If-None-Match') === $etag ? response('', 304, $headers) : response($script, 200, $headers);
    }

    /** A kept picture, through an address the server signed and that expires. */
    public function file(Request $request): Response
    {
        try {
            $path = Crypt::decryptString((string) $request->query('p'));
        } catch (Throwable) {
            abort(404);
        }

        $bytes = str_starts_with($path, 'try-on/') ? Files::get($path) : null;
        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    /** @return array<string, mixed> */
    private function state(TryOnJob $job): array
    {
        return [
            'id' => $job->id,
            'status' => $job->status,
            'stage' => $job->stage,
            'failure' => $job->failure,
            'image' => $job->status === TryOnJob::READY ? Files::url($job->result_path) : null,
            'note' => __('try_on::try_on.ai_note'),
        ];
    }

    private function site(Request $request, string $site): StoreConnection|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin !== null && ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        if (! Features::enabled('try_on.enabled', $connection->shop_id) && ! self::test($request, $connection)) {
            return response()->json(['error' => 'off'], 404);
        }

        return $connection;
    }

    /**
     * The store team's test: the preview key (the one `let_agents_preview` carries) lets Try-On run on
     * the team's own browser while it is off for shoppers. Every limit and every cost still counts.
     */
    private static function test(Request $request, StoreConnection $connection): bool
    {
        $key = $request->input('preview', $request->query('preview'));

        return is_string($key) && $key !== '' && hash_equals($connection->previewKey(), $key);
    }

    /**
     * In the team's test, the page's own product (its name and main picture) for a product the
     * catalog has not read yet. The picture is fetched by the server, so it must be on the store's own
     * address or Shopify's file server.
     *
     * @return array{title: string, image: string}|null
     */
    private static function pageProduct(Request $request, StoreConnection $connection): ?array
    {
        $title = trim(mb_substr((string) $request->input('title', $request->query('title', '')), 0, 200));
        $image = trim((string) $request->input('image', $request->query('image', '')));
        $image = str_starts_with($image, '//') ? 'https:'.$image : $image;
        $host = strtolower((string) parse_url($image, PHP_URL_HOST));
        $store = preg_replace('/^www\./', '', strtolower((string) parse_url((string) $connection->site_url, PHP_URL_HOST)));
        $allowed = $host !== '' && (
            ($store !== '' && ($host === $store || str_ends_with($host, '.'.$store)))
            || $host === 'cdn.shopify.com' || str_ends_with($host, '.myshopify.com')
        );

        if ($title === '' || ! str_starts_with($image, 'https://') || ! $allowed) {
            return null;
        }

        return ['title' => $title, 'image' => $image];
    }

    /** The class the team named for its test, on a product the scan did not mark. */
    private static function testClass(Request $request, StoreConnection $connection): ?string
    {
        $class = (string) $request->input('class', $request->query('class', ''));

        return self::test($request, $connection) && TryOnClasses::group($class) !== null ? $class : null;
    }

    private static function visitor(string $shopId, string $visitor): string
    {
        return hash('sha256', $shopId.'|'.$visitor);
    }
}
