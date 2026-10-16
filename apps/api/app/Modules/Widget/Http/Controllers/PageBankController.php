<?php

namespace App\Modules\Widget\Http\Controllers;

use App\Core\Facades\Settings;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Widget\Actions\BuildPageBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/widget/{site}/page?type=product|content&id=123&locale=he[&preview=key]
 *
 * What the storefront widget shows on one page. Public data only, so it is cached and may be
 * read from any page of the store. The preview key only tells the widget whether the store
 * team is looking; it changes nothing in the content.
 */
final class PageBankController
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    public function __invoke(Request $request, BuildPageBank $build, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin !== null && ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $type = (string) $request->query('type');
        $id = (string) $request->query('id');
        $locale = substr((string) $request->query('locale', 'he'), 0, 2);

        if (! in_array($type, BuildPageBank::TYPES, true) || ! preg_match(self::ID_PATTERN, $id)) {
            return response()->json(['error' => 'invalid_page'], 422);
        }

        $shopId = $connection->shop_id;
        $seconds = (int) Settings::get('widget.page_cache_seconds');
        $bank = $seconds > 0
            ? Cache::remember("widget:page:{$shopId}:{$type}:{$id}:{$locale}", $seconds, fn (): array => $build->handle($shopId, $type, $id, $locale))
            : $build->handle($shopId, $type, $id, $locale);

        $preview = $request->query('preview');
        $bank['preview'] = is_string($preview) && hash_equals($connection->previewKey(), $preview);

        // The store team, in preview, gets a link to the panel page that says why each circle is there.
        if ($bank['preview']) {
            $bank['explain_url'] = url('operator/widget/page').'?'.http_build_query(['shop' => $shopId, 'type' => $type, 'id' => $id]);
        }

        // Cross-origin reads are allowed by the app's CORS config for api/*; the data is public.
        return response()->json($bank)->header('Cache-Control', 'public, max-age='.min(60, $seconds));
    }
}
