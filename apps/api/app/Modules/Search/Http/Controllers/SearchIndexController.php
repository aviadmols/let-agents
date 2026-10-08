<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Http\StorefrontSite;
use App\Modules\Search\Models\SearchIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/search/{site}/index?locale=he
 *
 * Everything the search box needs to suggest as the shopper types, with no call per keystroke:
 * the shop's records (titles and their extra words, links and pictures), the shop's settings,
 * and the labels in the page's language. Public data, cached by version.
 */
final class SearchIndexController
{
    public function __invoke(Request $request, TenantContext $tenant, string $site): JsonResponse|Response
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;
        $locale = in_array($request->query('locale'), ['he', 'en'], true) ? (string) $request->query('locale') : 'he';

        if (! Features::enabled('search.storefront', $shopId)) {
            return response()->json(['enabled' => false])->header('Cache-Control', 'public, max-age=300');
        }

        $index = $tenant->run($shopId, fn () => SearchIndex::query()->where('shop_id', $shopId)->first(['hash', 'items']));

        if ($index === null) {
            return response()->json(['enabled' => false, 'reason' => 'not_built'])->header('Cache-Control', 'public, max-age=60');
        }

        $config = [
            'selector' => (string) Settings::get('search.input_selector', $shopId),
            'results' => (string) Settings::get('search.results', $shopId),
            'suggestions' => (int) Settings::get('search.suggestions', $shopId),
            'perGroup' => (int) Settings::get('search.results_per_group', $shopId),
            // The camera shows only where the shop's pictures already have vectors.
            'photos' => Features::enabled('search.photos', $shopId) && $tenant->run($shopId, fn (): bool => app(SemanticSearch::class)->picturesReady()),
            'photoMaxKb' => (int) Settings::get('search.photo_max_kb'),
            // A question may be sent to the assistant, only when the shopper presses Enter.
            'ask' => Features::enabled('assistant.on_search', $shopId),
            // What the site does not answer goes to the shop's WhatsApp, when the shop has one.
            'whatsapp' => self::whatsapp($shopId),
            'askWhatsapp' => Features::enabled('assistant.search_whatsapp', $shopId),
            'whatsappMessage' => trim((string) Settings::get('assistant.search_whatsapp_message', $shopId)) ?: (string) __('search::storefront.wa_message', [], $locale),
            'drawerSide' => (string) Settings::get('search.drawer_side', $shopId),
            'similar' => Features::enabled('search.similar', $shopId),
        ];
        $labels = trans('search::storefront', [], $locale);
        $etag = '"'.substr(hash('sha256', $index->hash.json_encode($config).$locale.json_encode($labels)), 0, 24).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300'];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        $data = json_decode((string) $index->items, true);

        return response()->json([
            'enabled' => true,
            'hash' => $index->hash,
            'config' => $config,
            'labels' => $labels,
            'items' => $data['items'] ?? [],
        ], 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The number the on-page module offers, read from the same settings; null when the shop has none. */
    private static function whatsapp(string $shopId): ?string
    {
        try {
            $number = (string) preg_replace('/\D/', '', (string) Settings::get('widget.whatsapp_number', $shopId));

            return Features::enabled('widget.whatsapp', $shopId) && strlen($number) >= 8 ? $number : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
