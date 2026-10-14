<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Modules\Search\Http\StorefrontSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search/{site}/sizes?locale=
 *
 * Whether the shop helps with a size that is gone on a product page, and the words for it. The
 * search script asks only on a product page where a size is out of stock; which sizes are gone is
 * read from the store itself in the browser. Public, cached, no model.
 */
final class SizeHelpController
{
    public const LABELS = ['size_gone_banner', 'size_gone_pick', 'size_gone_badge', 'size_similar_title', 'size_similar_none', 'size_similar_more'];

    public function __invoke(Request $request, string $site): JsonResponse
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;
        $locale = in_array($request->query('locale'), ['he', 'en'], true) ? (string) $request->query('locale') : 'he';
        $enabled = Features::enabled('search.storefront', $shopId) && Features::enabled('search.similar', $shopId) && Features::enabled('search.size_alternatives', $shopId);

        $labels = [];
        foreach ($enabled ? self::LABELS : [] as $key) {
            $labels[$key] = __('search::storefront.'.$key, [], $locale);
        }

        return response()->json(['enabled' => $enabled, 'labels' => $labels], 200, ['Cache-Control' => 'public, max-age=600'], JSON_UNESCAPED_UNICODE);
    }
}
