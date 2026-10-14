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
 *   GET  /api/v1/try-on/{site}/config?product=     whether the product can be tried, and on what
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
            $product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', (string) $request->query('product'))->first();
            $class = $product === null ? null : StartTryOn::classOf($product->id);

            return response()->json(['data' => [
                'tryable' => $class !== null,
                'class' => $class,
                'group' => $class === null ? null : TryOnClasses::group($class),
                'needs' => $class === null ? [] : TryOnClasses::SUBJECTS[$class],
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
        $result = app(TenantContext::class)->run($connection->shop_id, fn (): array => $start->handle(self::visitor($connection->shop_id, $visitor), (string) $request->input('photo'), (string) $request->input('product'), $answers));

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
        $job = app(TenantContext::class)->run($connection->shop_id, fn () => TryOnJob::query()->whereKey($id)->where('visitor_hash', self::visitor($connection->shop_id, $visitor))->first());

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

        if (! Features::enabled('try_on.enabled', $connection->shop_id)) {
            return response()->json(['error' => 'off'], 404);
        }

        return $connection;
    }

    private static function visitor(string $shopId, string $visitor): string
    {
        return hash('sha256', $shopId.'|'.$visitor);
    }
}
