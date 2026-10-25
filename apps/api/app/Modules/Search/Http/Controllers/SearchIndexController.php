<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\ValueSource;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsPopularity;
use App\Modules\Leads\Contracts\DescribesLeadForm;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Http\StorefrontSite;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Tenancy\Models\Shop;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

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
            // The shop's words for the search box, else its product page's (widget.whatsapp_message), else ours.
            'whatsappMessage' => trim((string) Settings::get('assistant.search_whatsapp_message', $shopId))
                ?: trim((string) Settings::get('widget.whatsapp_message', $shopId))
                ?: (string) __('search::storefront.wa_message', [], $locale),
            // When the shop answers its WhatsApp, one range a day from Sunday ("09:00-18:00", empty: closed),
            // read in the browser at the shop's time: this answer is cached.
            'hours' => ['timezone' => $zone = self::answeringZone($shopId),
                'days' => array_map(fn (string $day): string => self::answeringDay($day, $shopId, $zone), ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])],
            // Without a WhatsApp, or after hours: the chat asks for a name and a way back, kept as a callback.
            'callbacks' => Features::enabled('shoppers.callbacks', $shopId),
            // What a question with no answer is offered (assistant.no_answer as far as the shop can keep it):
            // team (WhatsApp in the hours, details outside them), details (the form), none ("not found" only).
            'noAnswer' => self::noAnswer($shopId),
            // The shop's own "leave details" form: its fields in order, each required or not; null: the plain one.
            'leadForm' => app(DescribesLeadForm::class)->forShop($shopId, $locale),
            // The shop's name, for the drawer's conversation: "Rocks and Gold AI".
            'shopName' => (string) (Shop::query()->whereKey($shopId)->value('name') ?? ''),
            // "Popular in the store" on the empty drawer: this shop's own searches, never sample words.
            // Worked out once an hour per shop and index: it reads the index's own words.
            'popular' => Cache::remember('search:popular:'.$shopId.':'.$index->hash, 3600, fn (): array => $tenant->run($shopId, fn (): array => self::popular($shopId, (string) $index->items))),
            // "Popular products" on the empty drawer: the shop's own most added to the cart lately, only those added at least
            // search.wanted_min_adds times and in the index; none, no section.
            'wanted' => Cache::remember('search:wanted:'.$shopId.':'.$index->hash, 3600, fn (): array => $tenant->run($shopId, fn (): array => self::wanted($shopId, (string) $index->items))),
            // "Similar items" only where the shop's vectors can answer: with none, the button would lead nowhere.
            'similar' => Features::enabled('search.similar', $shopId) && self::similarReady($tenant, $shopId),
            // What each product carries, as the shop set it on its search display screen.
            'price' => (bool) Settings::get('search.show_price', $shopId),
            'cart' => (bool) Settings::get('search.show_cart', $shopId),
            // Product pictures fill their square ("cover"), or show whole in a light frame ("frame").
            'images' => (string) Settings::get('search.image_fit', $shopId),
            // "Not sure what fits?" under the results: the store's AI recommends among them (inside), or an outside AI (outside).
            'notSure' => (string) Settings::get('search.not_sure', $shopId),
            'notSureMax' => (int) Settings::get('search.not_sure_max', $shopId),
            // "Ask my AI": the search and its products, handed to the shopper's own AI in a link.
            'aiShare' => Features::enabled('search.ai_share', $shopId),
            // "Share the results": the same link, to a friend.
            'shareResults' => Features::enabled('search.share_results', $shopId),
            // "Ask my AI" in the drawer's header: the whole store in one JSON, with the words to send and
            // where each AI opens with them. Ready here, so the press opens at once with no request.
            'aiAsk' => Features::enabled('search.ai_share', $shopId) && Features::enabled('search.agent_connector', $shopId)
                ? self::aiAsk((string) (Shop::query()->whereKey($shopId)->value('name') ?? ''), AgentCatalog::publicUrl('c/'.$site).'?format=json', $locale) : null,
        ] + self::lookFor($tenant, $shopId);
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
     * GET /api/v1/search/{site}/look
     *
     * How the drawer looks and where it takes over, read on every page before the index is needed: the
     * shop's look (Lets or the site's), the widget's language, the assistant's name, whether the theme's
     * own search box opens the drawer, and the out-of-stock row on a product page. Small and cached.
     */
    public function look(Request $request, TenantContext $tenant, string $site): JsonResponse|Response
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;
        $look = ['enabled' => Features::enabled('search.storefront', $shopId)] + self::lookFor($tenant, $shopId);
        $etag = '"'.substr(hash('sha256', (string) json_encode($look)), 0, 24).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300'];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response()->json($look, 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The shop's choices on its "how it looks" and "search window" settings, as the drawer reads them.
     *
     * @return array{theme: string, lang: string|null, assistant: string, takeOver: bool, rate: bool, oosSimilar: bool, nudge: bool, nudgeAt: string}
     */
    public static function lookFor(TenantContext $tenant, string $shopId): array
    {
        $language = (string) Settings::get('search.widget_language', $shopId);
        $name = trim((string) Settings::get('search.assistant_name', $shopId));

        return [
            // "lets": our own look; "store": the theme's font, main colour and corners, checked for contrast in the browser
            'theme' => Settings::get('search.theme', $shopId) === 'store' ? 'store' : 'lets',
            // null: as the site (the page's own language)
            'lang' => in_array($language, ['he', 'en'], true) ? $language : null,
            // the assistant's name beside its answers: the shop's own, else "<shop> AI"
            'assistant' => $name !== '' ? $name : trim((string) (Shop::query()->whereKey($shopId)->value('name') ?? 'Lets')).' AI',
            // the theme's own search box and search buttons open the drawer; off: only Lets' own and what is marked data-lets-*
            'takeOver' => Features::enabled('search.take_over_field', $shopId),
            // "Helpful?" under an assistant's answer
            'rate' => Features::enabled('search.rate_answers', $shopId),
            // a product or a variant sold out: a row on the product page with similar products in stock, same size
            'oosSimilar' => Features::enabled('search.out_of_stock_similar', $shopId) && self::similarReady($tenant, $shopId),
            // "Try our new search" beside the launcher, once, on a new shopper's first visit
            'nudge' => Features::enabled('search.nudge', $shopId),
            // what it points at: the site's own search icon, when the shop named it; '' = the drawer finds it
            'nudgeAt' => self::nudgeAt((string) Settings::get('search.nudge_at', $shopId)),
        ];
    }

    /**
     * The shop's search icon as a selector. The team copies a class from the browser's inspector, so a bare
     * class ("header__search-link") or the whole class attribute ("header__search-link icon") is read as classes.
     */
    private static function nudgeAt(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return preg_match('/^-?[A-Za-z_][\w-]*( -?[A-Za-z_][\w-]*)*$/', $value) === 1 ? '.'.str_replace(' ', '.', $value) : $value;
    }

    /**
     * What a question with no answer is offered, read from the same settings the assistant's replies are
     * (assistant.no_answer): the team only with WhatsApp on, the shop's own number and the offer on; details
     * only while the shop takes them (shoppers.callbacks); otherwise "not found" only.
     */
    private static function noAnswer(string $shopId): string
    {
        $chosen = (string) Settings::get('assistant.no_answer', $shopId);

        if ($chosen === 'team' && self::whatsapp($shopId) !== null && Features::enabled('assistant.search_whatsapp', $shopId)) {
            return 'team';
        }

        return $chosen !== 'none' && Features::enabled('shoppers.callbacks', $shopId) ? 'details' : 'none';
    }

    /** Whether the shop's vectors can answer "similar items": pictures or text. */
    private static function similarReady(TenantContext $tenant, string $shopId): bool
    {
        return $tenant->run($shopId, fn (): bool => app(SemanticSearch::class)->picturesReady() || app(SemanticSearch::class)->ready());
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

    /** Hebrew letters that join the front of a word ("והטבעת", "בזהב"). */
    private const PREFIX = '/^[והבלמשכ]+(?=\p{L}{2})/u';

    /**
     * The shop's popular searches of the last 30 days: a trend, never a moment or a few searches.
     * Shown only when the shop has enough searches to tell (search.popular_min_shop_searches); a phrase
     * searched often (search.popular_min_searches) on enough different days (search.popular_min_days),
     * that mostly found products, and made of whole words the shop's own products use, so a letter or
     * half a word typed and left ("א", "טבע") is never one. Every shopper sees them: nothing that reads
     * like a number or an address someone typed about themselves, no counted marker ("[photo]").
     * Inside the shop (TenantContext), so never another shop's.
     *
     * @return list<string>
     */
    /** @return list<string> index ids ("p:12") of the shop's most added to the cart, the most first */
    private static function wanted(string $shopId, string $index): array
    {
        $shown = (int) Settings::get('search.wanted_shown', $shopId);
        if ($shown <= 0) {
            return [];
        }

        // added to the cart at least search.wanted_min_adds times lately, the most added first: a small store's few adds
        // count, where the "popular" mark (a top share of a large catalogue) would show none
        $ids = AnalyticsPopularity::query()->where('adds', '>=', max(1, (int) Settings::get('search.wanted_min_adds', $shopId)))
            ->orderByDesc('adds')->orderBy('rank')->limit($shown * 3)->pluck('product_external_id')->map(fn ($id): string => 'p:'.$id)->all();
        // only what the index holds: a product removed since, or out of the search, is not offered
        $held = array_flip(array_column((array) (json_decode($index, true)['items'] ?? []), 'id'));

        return array_slice(array_values(array_filter($ids, fn (string $id): bool => isset($held[$id]))), 0, $shown);
    }

    private static function popular(string $shopId, string $index): array
    {
        $since = now()->subDays(30)->toDateString();
        if ((int) SearchTerm::query()->whereDate('day', '>=', $since)->sum('searches') < (int) Settings::get('search.popular_min_shop_searches', $shopId)) {
            return [];
        }

        $known = self::catalogWords($index);

        return SearchTerm::query()
            ->whereDate('day', '>=', $since)
            ->selectRaw('query, SUM(searches) as total')
            ->groupBy('query')
            ->havingRaw('SUM(searches) >= ?', [(int) Settings::get('search.popular_min_searches', $shopId)])
            // one row per query and day: the days it was searched on
            ->havingRaw('COUNT(*) >= ?', [(int) Settings::get('search.popular_min_days', $shopId)])
            ->havingRaw('SUM(empty) * 2 < SUM(searches)')
            ->orderByDesc('total')->orderBy('query')
            ->limit(40)
            ->pluck('query')
            ->map(fn ($q): string => trim((string) preg_replace('/\s+/u', ' ', (string) $q)))
            ->filter(fn (string $q): bool => $q !== '' && mb_strlen($q) <= 30 && ! preg_match('/\d{5,}|@|[\[\]]/u', $q) && self::wholeWords($q, $known))
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Every word the shop's products use, in their titles and extra words.
     *
     * @return array<string, true>
     */
    private static function catalogWords(string $index): array
    {
        $words = [];
        foreach ((array) (json_decode($index, true)['items'] ?? []) as $item) {
            if (($item['t'] ?? null) !== 'product') {
                continue;
            }
            foreach (self::words(($item['title'] ?? '').' '.($item['kw'] ?? '')) as $word) {
                $words[$word] = true;
                $words[(string) preg_replace(self::PREFIX, '', $word)] = true;
            }
        }

        return $words;
    }

    /** Each word is two letters or more and a whole word the shop's products use (with or without a joined prefix). */
    private static function wholeWords(string $query, array $known): bool
    {
        $words = self::words($query);

        return $words !== [] && collect($words)->every(fn (string $w): bool => mb_strlen($w) >= 2
            && (isset($known[$w]) || isset($known[(string) preg_replace(self::PREFIX, '', $w)])));
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [], fn (string $w): bool => $w !== ''));
    }

    /**
     * The clock the answering hours run by, the on-page module's rule (Widget's AnsweringZone), read by key:
     * a zone the team set for the hours on purpose, else the shop's own time zone.
     */
    private static function answeringZone(string $shopId): string
    {
        if (Settings::source('widget.whatsapp_timezone', $shopId) === ValueSource::Shop) {
            return (string) Settings::get('widget.whatsapp_timezone', $shopId);
        }

        $zone = (string) Shop::query()->whereKey($shopId)->value('timezone');

        try {
            return $zone !== '' ? (new DateTimeZone($zone))->getName() : (string) Settings::get('widget.whatsapp_timezone', $shopId);
        } catch (\Exception) {
            return (string) Settings::get('widget.whatsapp_timezone', $shopId);
        }
    }

    /**
     * One day of the answering week, the on-page module's rule (Widget's ShopWeek), read by key: the shop's own,
     * else the usual week where its clock runs (Israel Sunday to Thursday, elsewhere Monday to Friday).
     */
    private static function answeringDay(string $day, string $shopId, string $zone): string
    {
        $key = 'widget.hours_'.$day;

        if (Settings::source($key, $shopId) === ValueSource::Shop || Shop::israeli($zone)) {
            return trim((string) Settings::get($key, $shopId));
        }

        return match ($day) {
            'saturday', 'sunday' => '',
            default => trim((string) Settings::get($key, $shopId)) ?: trim((string) Settings::get('widget.hours_monday', $shopId)),
        };
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
