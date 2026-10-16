<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Http\StorefrontSite;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/v1/search/{site}/index?locale=he
 *
 * Everything the search drawer needs to suggest as the shopper types, with no call per keystroke:
 * the shop's records (titles and their extra words, links and pictures) and the shop's settings.
 * The drawer carries its own words. Public data, cached by version.
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
            // The store's own search page shows our regular results over it.
            'resultsPage' => Features::enabled('search.results_page', $shopId),
            // The camera shows only where the shop's pictures already have vectors.
            'photos' => Features::enabled('search.photos', $shopId) && $tenant->run($shopId, fn (): bool => app(SemanticSearch::class)->picturesReady()),
            'photoMaxKb' => (int) Settings::get('search.photo_max_kb'),
            // A question may be sent to the assistant, only when the shopper presses Enter.
            'ask' => Features::enabled('assistant.on_search', $shopId),
            // What the site does not answer goes to the shop's WhatsApp, when the shop has one.
            'whatsapp' => self::whatsapp($shopId),
            'askWhatsapp' => Features::enabled('assistant.search_whatsapp', $shopId),
            'whatsappMessage' => trim((string) Settings::get('assistant.search_whatsapp_message', $shopId)) ?: (string) __('search::storefront.wa_message', [], $locale),
            // When the shop answers its WhatsApp, one range a day from Sunday ("09:00-18:00", empty: closed),
            // read in the browser at the shop's time: this answer is cached.
            'hours' => ['timezone' => (string) Settings::get('widget.whatsapp_timezone', $shopId),
                'days' => array_map(fn (string $day): string => trim((string) Settings::get('widget.hours_'.$day, $shopId)), ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])],
            // Without a WhatsApp, or after hours: the chat asks for a name and a way back, kept as a callback.
            'callbacks' => Features::enabled('shoppers.callbacks', $shopId),
            // The shop's name, for the drawer's conversation: "Rocks and Gold AI".
            'shopName' => (string) (Shop::query()->whereKey($shopId)->value('name') ?? ''),
            // "Popular in the store" on the empty drawer: this shop's own searches, never sample words.
            'popular' => $tenant->run($shopId, fn (): array => self::popular()),
            // "Similar items" only where the shop's vectors can answer: with none, the button would lead nowhere.
            'similar' => Features::enabled('search.similar', $shopId) && $tenant->run($shopId, fn (): bool => app(SemanticSearch::class)->picturesReady() || app(SemanticSearch::class)->ready()),
            // What each product carries, as the shop set it on its search display screen.
            'price' => (bool) Settings::get('search.show_price', $shopId),
            'cart' => (bool) Settings::get('search.show_cart', $shopId),
            // "Ask my AI": the search and its products, handed to the shopper's own AI in a link.
            'aiShare' => Features::enabled('search.ai_share', $shopId),
            // "Share the results": the same link, to a friend.
            'shareResults' => Features::enabled('search.share_results', $shopId),
            // "Ask my AI" in the drawer's header: the whole store in one JSON, with the words to send and
            // where each AI opens with them. Ready here, so the press opens at once with no request.
            'aiAsk' => Features::enabled('search.ai_share', $shopId) && Features::enabled('search.agent_connector', $shopId)
                ? self::aiAsk((string) (Shop::query()->whereKey($shopId)->value('name') ?? ''), AgentCatalog::publicUrl('c/'.$site).'?format=json', $locale) : null,
        ];
        $etag = '"'.substr(hash('sha256', $index->hash.json_encode($config).$locale), 0, 24).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300'];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        $data = json_decode((string) $index->items, true);

        return response()->json([
            'enabled' => true,
            'hash' => $index->hash,
            'config' => $config,
            'items' => $data['items'] ?? [],
            // The filters beside the results and the phrases that are actions (BuildSearchIndex).
            'facets' => $data['facets'] ?? [],
            'size' => $data['size'] ?? null,
            'actions' => $data['actions'] ?? [],
        ], 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The words a shopper sends their own AI (in the shopper's language, so the AI answers in it)
     * with the store's JSON, and where each AI opens with them already typed.
     *
     * @return array{prompt: string, open: array<string, string>}
     */
    private static function aiAsk(string $store, string $url, string $locale): array
    {
        $words = (string) __('search::share.ask', ['store' => $store, 'url' => $url], $locale);

        return [
            'prompt' => $words,
            'open' => array_map(fn (string $at): string => str_replace('{q}', rawurlencode($words), $at), ShareController::OPEN),
        ];
    }

    /**
     * The shop's most searched words of the last 30 days that found products. Every shopper sees them,
     * so only words many searches share: at least three, short, and nothing that reads like a number
     * someone typed about themselves. Inside the shop (TenantContext), so never another shop's.
     *
     * @return list<string>
     */
    private static function popular(): array
    {
        return SearchTerm::query()
            ->whereDate('day', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('query, SUM(searches) as total')
            ->groupBy('query')
            ->havingRaw('SUM(searches) >= ?', [3])
            ->havingRaw('SUM(empty) * 2 < SUM(searches)')
            ->orderByDesc('total')->orderBy('query')
            ->limit(20)
            ->pluck('query')
            ->map(fn ($q): string => trim((string) $q))
            // a counted marker ("[photo]" for a photo search, a redacted piece) is not a word anyone searched
            ->filter(fn (string $q): bool => $q !== '' && mb_strlen($q) <= 30 && ! preg_match('/\d{5,}|@|[\[\]]/u', $q))
            ->take(12)
            ->values()
            ->all();
    }

    /** The number the on-page module offers, read from the same settings; null when the shop has none. */
    private static function whatsapp(string $shopId): ?string
    {
        try {
            // The shop's own number only: a number saved for every shop at once is never another shop's.
            $number = (string) preg_replace('/\D/', '', (string) Settings::overrideFor('widget.whatsapp_number', $shopId));

            return Features::enabled('widget.whatsapp', $shopId) && strlen($number) >= 8 ? $number : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
