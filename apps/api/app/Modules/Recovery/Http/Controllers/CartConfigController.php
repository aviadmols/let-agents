<?php

namespace App\Modules\Recovery\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/cart/{site}/config — whether the shop asks for an email before payment, and the
 * popup's words in the page's language. Public and cached: no model, nothing about the shopper.
 */
final class CartConfigController
{
    public function __invoke(Request $request, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin !== null && ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $shopId = (string) $connection->shop_id;
        $locale = in_array($request->query('locale'), ['he', 'en'], true) ? (string) $request->query('locale') : 'he';

        if (! Features::enabled('recovery.capture', $shopId)) {
            return response()->json(['on' => false])->header('Cache-Control', 'public, max-age=300');
        }

        $text = fn (string $key): string => trim((string) Settings::get('recovery.popup_'.$key, $shopId)) ?: (string) __('recovery::storefront.'.$key, [], $locale);

        return response()->json([
            'on' => true,
            'title' => $text('title'),
            'text' => $text('text'),
            'button' => $text('button'),
            'skip' => $text('skip'),
            'consent' => $text('consent'),
            'labels' => [
                'email' => __('recovery::storefront.email', [], $locale),
                'invalid' => __('recovery::storefront.invalid', [], $locale),
                'need_consent' => __('recovery::storefront.need_consent', [], $locale),
                'close' => __('recovery::storefront.close', [], $locale),
            ],
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
