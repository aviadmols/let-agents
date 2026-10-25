<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Contracts\PictureContent;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Contracts\RelatedByTags;
use App\Modules\Search\Models\SearchResolution;
use App\Modules\Search\Support\Colours;
use App\Modules\Search\Support\DescribedProducts;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\Looks;
use App\Modules\Search\Support\PhotoLook;
use App\Modules\Search\Support\PhotoPlacer;
use App\Modules\Search\Support\SearchTrace;
use App\Modules\Search\Support\SimilarWants;
use App\Modules\Search\Support\WordMeanings;
use Illuminate\Support\Facades\Cache;

/**
 * One search in a shop: by spelling and by meaning, merged, grouped by what was found.
 *
 *   1. spelling   HebrewSearch over the nightly index: typos, plural and singular, full and
 *                 defective spelling, an English keyboard. No model, a few milliseconds.
 *   2. meaning    Retrieval's index by meaning, and its pictures where they are indexed, for words that say what the shopper wants
 *                 rather than what the product is called ("משהו לחבר קרשים"). One small
 *                 embedding per new wording, kept, so a repeated query costs nothing.
 *   3. merge      records that hold every word as typed come first, in spelling order; the
 *                 rest are merged by reciprocal rank, so a record both ways found rises.
 *   4. group      products (what was in stock last night first), guides and pages, categories.
 *   5. count      the query, normalized, is counted for the day, with how many results it had.
 *
 * Nothing about the shopper is kept.
 */
final class SearchCatalog
{
    public const GROUPS = ['answer', 'product', 'content', 'category'];

    /** A title's first word on more than this share of the products is the store's, not a kind a search names. */
    private const KIND_MAX_SHARE = 0.6;

    /** Far by meaning and vouched for only by the store's own question: at most this many nearest products. */
    private const ANSWERED_FEW = 4;

    /**
     * A similar item's place where looks lead: the pictures' own order, the share of their described words, and how
     * near the colour it shows most is (bySeenWords). Weights that add up to one.
     */
    private const SEEN_ORDER = 0.4;

    private const SEEN_WORDS = 0.2;

    private const SEEN_COLOUR = 0.4;

    /** A filter of whom a product is for: kept when a search that found nothing is loosened (loosened()). */
    private const WHOM_FACETS = ['audience', 'gender', 'for_whom', 'target_audience', 'age_group'];

    /** Words a search does not know, read as the store's word they mean (search.word_meanings), at most. */
    private const NEAR_WORDS = 2;

    /** A search that found nothing is tried again without one word, this many words at most (loosened()). */
    private const LOOSE_TRIES = 3;

    /** Words that only join or point at a filter: never what a loosened search left out. */
    private const LOOSE_WORDS = '/^(?:עמ|ו?בצבע|צבע|ו?בגוונ|with|in|colou?r|for|and)$/u';

    /** A colour asked for: the pictures of this many products read for the colour they show most (byPictureColour). */
    private const PICTURE_COLOUR_READ = 120;

    /** Where looks matter, its alike are of its colour: the same family, whatever the tone (Colours::near). */
    private const SAME_COLOUR = 0.8;

    /** Where looks matter, this many pictures and meanings are read for its alike: few of the nearest are of its colour. */
    private const LOOKS_POOL = 150;

    /** Words of a picture that say it is printed, not plain: a print, stripes, checks, flowers, an animal's coat. */
    private const PATTERNED = '/הדפס|מודפס|פסים|משבצ|פרחונ|פרחים|דוגמ|נמר|זברה|הסוואה|פייזלי|נקוד|print|stripe|check|plaid|tartan|floral|pattern|leopard|zebra|camo|paisley|polka|dots/iu';

    /** Pictures compared with a tagged photo: every scanned picture of a shop this size. */
    private const PHOTO_ALL = 5000;

    /** Around the object cut from a photo: a margin of this share of its size, and no cut under this many pixels. */
    private const CUT_MARGIN = 0.12;

    private const CUT_MIN_PIXELS = 20;

    /** A cut narrower than this is scaled up to CUT_UPSCALE_TO on its long side before it is compared. */
    private const CUT_UPSCALE_BELOW = 256;

    private const CUT_UPSCALE_TO = 512;

    /** Products a tagged photo returns, for the shopper to narrow by tag in the browser. */
    private const PHOTO_TAGGED = 60;

    /** At least this many products of the kind photographed, and a photo search shows only that kind. */
    private const PHOTO_KIND_MIN = 3;

    /** In a shop of tools (search.photo_match = function), what a picture shows weighs at least this against how it looks. */
    private const PHOTO_FUNCTION_WEIGHT = 0.75;

    /** Products a tag of words brings, before the picture orders them. */
    private const PHOTO_PER_TAG = 200;

    /** How far down a product goes that has what the photographed object plainly lacks. */
    private const LACKS_PENALTY = 0.5;

    /** A product whose words agree with the colours wanted this much comes before the rest; this much against, after. */
    private const COLOUR_AGREES = 0.4;

    private const COLOUR_DISAGREES = -0.3;

    /** A category tag brings all its products, up to this many. */
    private const PHOTO_CATEGORY_ALL = 5000;

    /** The reader's words for the object are read from at most this many of their first words. */
    private const OBJECT_WORDS = 4;

    /** Products the object's words name, put first in a photo's results. */
    private const OBJECT_NAMED = 24;

    /** How much more the detail that tells the object apart most counts than any other detail. */
    private const KEY_DETAIL_WEIGHT = 2.0;

    /** Products of the asked-about kind put first for a question. */
    private const SUBJECT_PRODUCTS = 12;

    /** Reciprocal rank constant: how much a lower rank still counts. */
    private const RRF_K = 60;

    /** How much the tags a product shares count beside the meaning and the picture, in the similar products. */
    private const TAG_SHARE = 2.0;

    /** The kinds of article that grow old (models, prices, what is new); a how-to guide does not. */
    private const AGEING_KINDS = ['news', 'product_review', 'buying_guide'];

    /** The guides by meaning whose products are suggested for a question about a need. */
    private const GUIDES_FOR_PRODUCTS = 2;

    /** Products taken from each of those guides, in the guide's order. */
    private const GUIDED_PRODUCTS = 6;

    /** What a guide's products weigh in the merge beside what the words, the meaning and the pictures found. */
    private const GUIDED_WEIGHT = 0.5;

    /** A product its description names (DescribedProducts) weighs like one found by meaning, a little less. */
    private const DESCRIBED_WEIGHT = 0.9;

    /**
     * Products the spelling found from which the meaning only adds what is very near: the meaning pass runs on
     * every search now (as Aviad asked, 2026-10-25), and when the words already found a few, what is merely
     * close by meaning would pad them (search.meaning_only_min_similarity instead of semantic_min_similarity).
     */
    private const SPELLED_ENOUGH = 4;

    /** A question's subject category is too wide above this share of the store's products and this many (subjectProducts()). */
    private const SUBJECT_MAX_SHARE = 0.3;

    private const SUBJECT_MAX_PRODUCTS = 24;

    /** A filter about colour or metal ("מתכת", "צבע האבן", variant_color): the shop sorts by it. Not a name that looks like a colour. */
    private const COLOUR_FILTER = '/colou?r|metal|צבע|מתכת/iu';

    /** A shopper's own search (handle's readWords): only then is a word the store does not write read by a model. */
    private bool $readWords = false;

    /** What the search being explained did, stage by stage (explain()); null for a shopper's search. */
    private ?SearchTrace $trace = null;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SemanticSearch $semantic,
        private readonly CountSearch $counter,
        private readonly ReadPhotoTags $photoTags,
        private readonly KeepPhotoSearch $keeper,
        private readonly RankPhotoResults $ranker,
    ) {}

    /**
     * One search as a shopper's Enter runs it, with what each stage did, for the search check screen: how each
     * result was found and why what was found was left out. Never counted; nothing about it is kept.
     *
     * @return array{result: array<string, mixed>, trace: SearchTrace}
     */
    public function explain(string $shopId, string $query, int $perGroup): array
    {
        $trace = $this->trace = new SearchTrace;

        try {
            return ['result' => $this->handle($shopId, $query, null, false, $perGroup), 'trace' => $trace];
        } finally {
            $this->trace = null;
        }
    }

    /**
     * @param  list<string>|null  $only  groups to return; null for all
     * @return array{query: string, total: int, semantic: bool, single: array{id: string, by: string}|null, focus: array{options: list<array{query: string, count: int}>, category: array<string, mixed>|null}|null, groups: array<string, list<array<string, mixed>>>, counts: array<string, int>}
     */
    /**
     * @param  bool  $meaning  false while a shopper is still typing: spelling only, code only, no vector
     */
    public function handle(string $shopId, string $raw, ?array $only = null, bool $count = true, ?int $perGroup = null, bool $meaning = true, array $visit = [], bool $readWords = false): array
    {
        // a word the store does not write is read by a model (WordMeanings) for a shopper's own search only: never for the
        // searches the store's checks and writers make of it
        $this->readWords = $readWords;
        try {
            $result = $this->searchOnce($shopId, $raw, $only, $count, $perGroup, $meaning, $visit);

            return ($result['total'] ?? 0) === 0 ? $this->loosened($shopId, $raw, $only, $perGroup, $meaning, $result) : $result;
        } finally {
            $this->readWords = false;
        }
    }

    /**
     * Nothing found for words that ask several things ("חולצה לגברים בצבע לבן עם פסים": no shirt here is filed under men and
     * white and says stripes): the search tries again, looser, the way the AI would answer "the nearest there is", in code.
     * Each word that says how it is, left out in turn, a colour first ("לבן": the striped men's shirts), then the others from
     * the last ("פסים": the white men's shirts); never the first word (what it is) and never whom it is for. The first that
     * finds is shown, saying what it left out (loosened), and the others that find are offered beside it. A word alone is
     * never loosened: nothing is looser.
     *
     * @param  array<string, mixed>  $result  the search as typed, which found nothing
     * @return array<string, mixed>
     */
    private function loosened(string $shopId, string $raw, ?array $only, ?int $perGroup, bool $meaning, array $result): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim($raw)) ?: [], fn (string $w): bool => $w !== ''));
        // a word alone is never loosened, and narrower searches already offered stay what is offered
        if (count($words) < 2 || ($result['focus']['options'] ?? []) !== []) {
            return $result;
        }
        $index = $this->tenant->run($shopId, fn (): ?array => LoadedIndex::for($shopId));
        if ($index === null) {
            return $result;
        }
        $facets = (array) ($index['facets'] ?? []);
        $whomSaid = array_map(fn (array $n): string => HebrewSearch::normalize((string) $n['said']), array_filter(HebrewSearch::facets($raw, $facets),
            fn (array $n): bool => in_array((string) ($facets[intdiv((int) $n['code'], 1000)]['k'] ?? ''), self::WHOM_FACETS, true)));

        // the words that say how it is: not the first (what it is), not whom it is for, not one that only joins
        $first = null;
        $describing = [];
        foreach ($words as $at => $word) {
            $plain = HebrewSearch::normalize($word);
            if ($plain === '' || HebrewSearch::isFiller($plain) || preg_match(self::LOOSE_WORDS, $plain) === 1) {
                continue;
            }
            if ($first === null) {
                $first = $at;

                continue;
            }
            if (array_filter($whomSaid, fn (string $s): bool => $s !== '' && str_contains($plain, $s)) !== []) {
                continue;
            }
            $describing[] = ['at' => $at, 'word' => $word, 'colour' => Colours::mentions($word) !== []];
        }
        // a colour first, then the others from the last typed
        usort($describing, fn (array $a, array $b): int => [$b['colour'], $b['at']] <=> [$a['colour'], $a['at']]);

        $rungs = [];
        foreach (array_slice($describing, 0, self::LOOSE_TRIES) as $d) {
            $rest = $words;
            unset($rest[$d['at']]);
            $rungs[] = ['query' => self::withoutLooseWords(implode(' ', $rest)), 'left' => [$d['word']]];
        }

        // the kind it named holds in a looser search too: "תיק" alone keeps what is near it (a keychain "Micro Old Skool תיק"),
        // "תיק לבן" without the white is the bags
        $kinds = self::askedKinds($index, $raw);
        $found = [];
        foreach ($rungs as $rung) {
            if ($rung['query'] === '' || HebrewSearch::normalize($rung['query']) === HebrewSearch::normalize($raw)) {
                continue;
            }
            $tried = $this->searchOnce($shopId, $rung['query'], $only, false, $perGroup, $meaning);
            if ($kinds !== []) {
                $tried = self::keptToKinds($tried, $index, $kinds);
            }
            // products only: a guide that names a word is no nearest product
            if (($tried['counts']['product'] ?? 0) > 0) {
                $found[] = $rung + ['result' => $tried];
            }
        }
        if ($found === []) {
            return $result;
        }
        $first = array_shift($found);

        return $first['result'] + ['loosened' => [
            'left' => $first['left'],
            'query' => $first['query'],
            'also' => array_map(fn (array $f): array => ['query' => $f['query'], 'count' => (int) ($f['result']['counts']['product'] ?? 0)], $found),
        ]];
    }

    /**
     * A search's products kept to the kinds asked for (ofKinds), its counts and ids with them.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $index
     * @param  array<string, int>  $kinds
     * @return array<string, mixed>
     */
    private static function keptToKinds(array $result, array $index, array $kinds): array
    {
        $of = fn (string $id): bool => self::ofKinds((array) ($index['records'][$id] ?? []), $kinds);
        $products = array_values(array_filter((array) ($result['groups']['product'] ?? []), fn (array $p): bool => $of('p:'.(string) ($p['external_id'] ?? ''))));
        $ids = array_values(array_filter((array) ($result['ids'] ?? []), fn (string $id): bool => ! str_starts_with($id, 'p:') || $of($id)));
        $result['groups']['product'] = $products;
        $result['ids'] = $ids;
        $all = count(array_filter($ids, fn (string $id): bool => str_starts_with($id, 'p:')));
        $result['counts']['product'] = max(count($products), $all);
        $result['total'] = array_sum((array) $result['counts']);

        return $result;
    }

    /** "בצבע" and "in colour" left alone once the colour is taken out: no word of what is searched. */
    private static function withoutLooseWords(string $query): string
    {
        $query = (string) preg_replace('/(^|\s)(?:ו?בצבע|ו?בגוון|in|colou?r)(?=\s|$)/iu', ' ', $query);
        // and a joining word left at the end ("חולצה לגברים לבן עם")
        $query = (string) preg_replace('/(?:\s(?:עם|ו|או|with|and|or|for))+\s*$/iu', '', ' '.trim($query));

        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }

    /** One search pass, as typed: what handle() loosens when it finds nothing. */
    private function searchOnce(string $shopId, string $raw, ?array $only, bool $count, ?int $perGroup, bool $meaning, array $visit = []): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $raw, $only, $count, $perGroup, $meaning, $visit): array {
            // "עד 2500 ש״ח": the amount is not searched for; products outside the range are kept away.
            [$priced, $minPrice, $maxPrice] = HebrewSearch::priceRange(mb_substr($raw, 0, 120));
            // "בלי יהלומים": that word is not searched for, and what holds it is kept away.
            [$query, $without] = HebrewSearch::without($priced);
            // one letter is not a search: "ס" found the one book whose author is "תומס ס. טיילור" and called it the
            // exact match. Under two letters or digits it is as nothing typed.
            $index = mb_strlen((string) preg_replace('/[^\p{L}\p{N}]/u', '', $query)) < 2 ? null : LoadedIndex::for($shopId);
            // "הכי זול", "במבצע", "גדול מ-2": actions, not words. The same phrases in every shop, less
            // those its index leaves out (no size: "גדול" stays a word).
            [$query, $actions] = HebrewSearch::actions($query, $index['actions'] ?? []);
            // "אני רוצה לקנות מתנה לאשתי טבעת יהלום": a sentence no product holds is searched by what it asks for ("טבעת
            // יהלומ"), then read for filters like any search. Words a product holds together are searched as typed. The
            // meaning reads the whole sentence: "משהו לחבר קרשים" is about joining boards.
            $sentence = null;
            $scattered = null;
            if ($index !== null && str_contains(trim($query), ' ') && self::whole(HebrewSearch::search($index['engine'], $query)) === []) {
                $scattered = $query;
                $meant = HebrewSearch::meant($index['engine'], $query);
                $sentence = $meant !== HebrewSearch::normalize($query) ? HebrewSearch::normalize($query) : null;
                $query = $meant;
            }
            // "שטיח כחול 80 על 150": a colour or a size the shop filters by is a filter, not a word (the page shows it as one).
            $worded = $query;
            $query = $index === null ? $query : HebrewSearch::withoutFacetWords($index, $query);
            // The filter read from the words is applied here too, as the drawer applies it: "ספר של תומס סואל" is his
            // book, not every book with the author's name taken off. Within one filter any value; across filters all.
            $read = [];
            if ($index !== null && $query !== $worded) {
                foreach (HebrewSearch::facets($worded, (array) ($index['facets'] ?? [])) as $named) {
                    $read[intdiv((int) $named['code'], 1000)][(int) $named['code']] = (string) $named['label'];
                }
            }
            $empty = ['query' => $query, 'total' => 0, 'semantic' => false, 'groups' => array_fill_keys(self::GROUPS, []), 'counts' => array_fill_keys(self::GROUPS, 0)];
            $this->trace?->step('read', array_filter([
                'short' => $index === null, 'min_price' => $minPrice, 'max_price' => $maxPrice, 'without' => $without,
                'actions' => $actions['ids'], 'sentence' => $sentence === null ? null : $query, 'facets' => array_values(array_merge(...array_values($read ?: [[]]))),
            ], fn ($v): bool => $v !== null && $v !== [] && $v !== false));

            if ($index === null || $query === '') {
                return $empty;
            }

            // "טבעת או עגילים": each side on its own, their results taken in turn.
            $spelling = self::either($index['engine'], $query);
            // A product code, or the only product that holds every word: shown alone, the rest on request.
            $asks = str_contains($raw, '?') || HebrewSearch::asks($query);
            $single = $minPrice === null && $maxPrice === null && $without === [] && $actions['ids'] === [] && ! $asks
                ? HebrewSearch::single($index['codes'] ?? [], $query, $spelling)
                : null;
            $single = $single !== null && isset($index['records'][$single['id']]) ? $single : null;
            // alone by its words only when it says them as words: "עצים" inside an earring's "נעצים" is no match to show alone
            if ($single !== null && $single['by'] === 'words') {
                $record = $index['records'][$single['id']];
                $text = (string) ($record['title'] ?? '').' '.(string) ($record['kw'] ?? '');
                // each word as typed, or without its own prefix letter ("בכסף" is said by "כסף")
                $single = array_all(array_filter(explode(' ', HebrewSearch::normalize($query))), fn (string $w): bool => HebrewSearch::says($text, $w)
                    || (mb_strlen($w) > 3 && preg_match('/^[והבלמשכ]/u', $w) === 1 && HebrewSearch::says($text, mb_substr($w, 1)))) ? $single : null;
            }
            // No product holds every word ("מקדחה בצבע שחור"): the narrower searches that do find, to choose from.
            $focus = $minPrice === null && $maxPrice === null && $without === [] && $actions['ids'] === [] && ! $asks ? HebrewSearch::focus($index['engine'], $query) : [];

            // A sentence ("מה הכי טוב למברגה ביתית וזולה") holds words no product has: search
            // what it is about instead, so a question finds the products it asks about.
            if ($spelling === [] && str_contains($query, ' ') && $asks) {
                $about = HebrewSearch::aboutWords($index['engine'], $query);
                $spelling = $about !== '' && $about !== $query ? HebrewSearch::search($index['engine'], $about) : [];
                $this->trace?->step('read', ['about' => $about]);
            }
            // A guide or a page is a result only when it holds every word: one that shares a word of
            // "מברגה שחורה" is not about black screwdrivers. Products keep their wider net.
            $spelling = array_values(array_filter($spelling, fn (array $hit): bool => ! str_starts_with($hit['id'], 'c:') || $hit['full']));
            // A code no product of the index has ("53077"): nothing is near it by meaning, so nothing is
            // shown by meaning. If the catalogue has it and the index not yet, the index is built again now.
            $code = $single === null && HebrewSearch::looksLikeCode($query);
            if ($code) {
                $this->missingCode($shopId, $query, $index);
            }
            // "7 באוקטובר": when the words found few products, those whose description says it too, after them
            // (the books about the date that only their description names). Not for a product code.
            $foundByWords = count(array_filter($spelling, fn (array $hit): bool => str_starts_with($hit['id'], 'p:')));
            $described = ! $code && ($single['by'] ?? null) !== 'sku' && $foundByWords < DescribedProducts::FEW
                ? array_values(array_filter(DescribedProducts::find($shopId, (string) $index['hash'], $query), fn (string $id): bool => isset($index['records'][$id])))
                : [];
            $describedIds = array_flip($described);
            if ($this->trace !== null) {
                $this->trace->step('read', array_filter(['question' => $asks, 'code' => $code, 'single' => $single['id'] ?? null]));
                foreach ($spelling as $hit) {
                    $this->trace->found($hit['id'], 'words', ['exact' => (bool) $hit['exact'], 'full' => (bool) ($hit['full'] ?? false)]);
                }
                foreach ($described as $id) {
                    $this->trace->found($id, 'description');
                }
            }
            $byMeaning = $meaning && ! $code;
            // "חולצה עם האות M" in a jewellery store: a word the catalogue has nothing close to asks for what the store may not
            // have. What is nearest by meaning or picture may still be it ("קורה בהירה": a pine plank) or may not (link chains):
            // a few of them, and the drawer says the word was not found (unknown) rather than showing dozens as if it were.
            $unknown = $byMeaning ? HebrewSearch::unknownWords($index['engine'], $query) : [];
            // a word the store does not write, read as the store's word it means ("מפוספס": "פסים", "נעלי ספורט": "סניקרס"),
            // by a small model among the store's own words, once per word (WordMeanings): at a pause or on Enter only.
            // What says that word is found with the rest, and the word is no longer "not found".
            $nearWords = [];
            if ($unknown !== [] && $this->readWords && Features::enabled('search.word_meanings', $shopId)) {
                foreach (array_slice($unknown, 0, self::NEAR_WORDS) as $word) {
                    $term = app(WordMeanings::class)->read($shopId, $index, HebrewSearch::withFinals((string) $word));
                    if ($term !== null) {
                        $nearWords[(string) $word] = $term;
                    }
                }
                $unknown = array_values(array_diff($unknown, array_keys($nearWords)));
            }
            $termHits = [];
            foreach ($nearWords as $term) {
                foreach (HebrewSearch::search($index['engine'], $term) as $hit) {
                    if (str_starts_with((string) $hit['id'], 'p:') && ! in_array($hit['id'], $termHits, true)) {
                        $termHits[] = (string) $hit['id'];
                    }
                }
            }
            $spelled = array_column(array_filter($spelling, fn (array $hit): bool => str_starts_with($hit['id'], 'p:')), 'id');
            $near = $byMeaning ? $this->meaning($shopId, $sentence ?? $query, $index['records'], $spelled) : ['products' => [], 'content' => [], 'best' => 0.0];
            // "מדע בדיוני" in a store with no science fiction: no product holds the words, and the nearest by meaning is far
            // (.36: books on politics). Nothing is shown as if it were found; the drawer says so, with the AI one press away.
            // How near is near enough is the store's (search.meaning_only_min_similarity).
            // A search that says how the thing looks keeps what the pictures find ("קורה בהירה": a pine plank); any other
            // search leans on the meaning alone, since every cover is about as near to a genre as any other (.6 to .7).
            // The words found a few already and say nothing of how it looks: no cover is near "a story about captivity"
            // more than any other, and the pictures would only pad them ("השבט", "העדן" after the books about captivity).
            $pictures = $byMeaning && (count($spelled) < self::SPELLED_ENOUGH || HebrewSearch::visual($query)) ? $this->pictures($shopId, $query, $index['records']) : [];
            $this->trace?->step('pictures', ['ran' => $byMeaning && (count($spelled) < self::SPELLED_ENOUGH || HebrewSearch::visual($query)), 'words_found' => count($spelled)]);
            // a question about a need ("איך מחברים קרשים לדק?") is not held to it: its guide and what the guide names answer it
            // ...unless the store itself answered a question holding the words ("לישון" in its own "מה טוב לישון?"): what
            // it answered about is its own, and the nearest by meaning are shown as for that question
            $answered = array_filter($spelling, fn (array $hit): bool => str_starts_with((string) $hit['id'], 'a:')) !== [];
            $weak = $byMeaning && ! $asks && ! $answered && self::whole($spelling) === [] && (float) $near['best'] < (float) Settings::get('search.meaning_only_min_similarity', $shopId)
                && ($pictures === [] || ! HebrewSearch::visual($query));
            // vouched for by the store's own question only (its answer may be a "no"): the few nearest, never a list
            if ($answered && ! $asks && self::whole($spelling) === [] && (float) $near['best'] < (float) Settings::get('search.meaning_only_min_similarity', $shopId)) {
                $near['products'] = array_slice($near['products'], 0, self::ANSWERED_FEW);
                $pictures = [];
            }
            // Far by meaning, yet its own description says what was searched: "פטריה לקשב וריכוז" is .34 from the formula
            // that speaks of "מיקוד הקשב" and "ריכוז", where an unrelated search is .1. Those near ones are shown, alone.
            $said = $weak ? DescribedProducts::saying($shopId, (string) $index['hash'], $query, $near['products']) : [];
            if ($said !== []) {
                $this->trace?->dropped([...$near['products'], ...$pictures], $said, 'meaning_far');
                $this->trace?->step('meaning', ['far' => true, 'said' => $said]);
                $near['products'] = $said;
                $pictures = [];
                $weak = false;
            }
            if ($weak) {
                $this->trace?->dropped([...$near['products'], ...$pictures], [], 'meaning_far');
                $this->trace?->step('meaning', ['far' => true]);
                $near['products'] = [];
                $pictures = [];
            }
            $this->trace?->step('read', ['unknown' => $unknown]);
            $meaning = $near['products'];
            // Guides and pages found by meaning are a group of their own, never a rank among products: an
            // old article never pushes a product down. What a guide about the need links to may still be
            // suggested, after what the words and the meaning found themselves (guided()).
            $guides = $this->guides($shopId, $near['content'], $index['records']);
            if ($unknown !== []) {
                $few = (int) Settings::get('search.unknown_results', $shopId);
                $this->trace?->dropped([...$meaning, ...$pictures], [...array_slice($meaning, 0, $few), ...array_slice($pictures, 0, $few)], 'unknown_few');
                $meaning = array_slice($meaning, 0, $few);
                $pictures = array_slice($pictures, 0, $few);
            }
            // what each filter below leaves out, for the check screen
            $ids = function () use (&$spelling, &$meaning, &$pictures): array {
                return $this->trace === null ? [] : [...array_column($spelling, 'id'), ...$meaning, ...$pictures];
            };

            // "טבעת יהלום סוליטר בכסף": when products hold every word, only they are shown, however
            // close by meaning or by picture a gold solitaire or a silver zircon ring may be. The
            // search widens to products holding most of the words only when none holds them all.
            if (str_contains($query, ' ') && ($whole = self::whole($spelling)) !== []) {
                $before = $ids();
                $spelling = array_values(array_filter($spelling, fn (array $hit): bool => isset($whole[$hit['id']]) || ! str_starts_with($hit['id'], 'p:')));
                $keep = fn (string $id): bool => isset($whole[$id]) || ! str_starts_with($id, 'p:');
                $meaning = array_values(array_filter($meaning, fn (string $id): bool => $keep($id) || isset($describedIds[$id])));
                $pictures = array_values(array_filter($pictures, $keep));
                $this->trace?->dropped($before, $ids(), 'not_all_words');
            }
            // "טופז": one word that many products hold as typed is what the shopper asked for. The stones only close to
            // it by meaning or by picture (citrine, a yellow sapphire) are left out. When few hold it ("בטריה" in a store
            // that says "סוללה"), the meaning fills in as before.
            if (! str_contains($query, ' ')) {
                $typed = [];
                foreach ($spelling as $hit) {
                    if ($hit['exact'] && str_starts_with($hit['id'], 'p:')) {
                        $typed[$hit['id']] = true;
                    }
                }
                if (count($typed) >= (int) Settings::get('search.word_enough', $shopId)) {
                    $before = $ids();
                    $spelling = array_values(array_filter($spelling, fn (array $hit): bool => isset($typed[$hit['id']]) || ! str_starts_with($hit['id'], 'p:')));
                    $meaning = array_values(array_filter($meaning, fn (string $id): bool => isset($typed[$id]) || isset($describedIds[$id]) || ! str_starts_with($id, 'p:')));
                    $pictures = array_values(array_filter($pictures, fn (string $id): bool => isset($typed[$id]) || ! str_starts_with($id, 'p:')));
                    $this->trace?->dropped($before, $ids(), 'word_enough');
                }
            }
            // "עגילים עד 500": with a price, what the words name is decided before the price, never
            // after it. Only products that hold the words: by meaning a pendant is close to
            // earrings. And when some hold them as typed (in the title, a category, an attribute or
            // a synonym), only those: "צמיד" is close to "צמודים" by spelling, and when no bracelet
            // is that cheap the stud earrings would be all that is left.
            $products = array_values(array_filter($spelling, fn (array $hit): bool => str_starts_with($hit['id'], 'p:')));
            if (($minPrice !== null || $maxPrice !== null) && $products !== []) {
                $before = $ids();
                $exact = array_values(array_filter($products, fn (array $hit): bool => $hit['exact']));
                $held = array_flip(array_column($exact !== [] ? $exact : $products, 'id'));
                $keep = fn (string $id): bool => isset($held[$id]) || ! str_starts_with($id, 'p:');
                $spelling = array_values(array_filter($spelling, fn (array $hit): bool => $keep($hit['id'])));
                $meaning = array_values(array_filter($meaning, $keep));
                $pictures = array_values(array_filter($pictures, $keep));
                $this->trace?->dropped($before, $ids(), 'price_words');
            }
            $pinned = $this->resolved($query, $index['records']);
            foreach ($this->trace === null ? [] : $pinned as $id) {
                $this->trace->found($id, 'resolved');
            }
            // A question about a kind of product ("איזה עץ…") shows that kind first: products of the
            // categories named after it, before products that only mention the word (a bracket
            // "לעמודי עץ"). The assistant then picks from them too.
            $subject = str_contains($query, ' ') && $asks
                ? $this->subjectProducts($index, $query, $spelling)
                : [];
            // A question about a need ("איך בונים פרגולה?") finds the guide about it: the products the guide
            // names come as one more list, the last and lightest, so they never beat a product found directly.
            $guided = $asks && $guides !== [] ? $this->guided($guides, $index['records']) : [];
            if (isset($whole) && $whole !== []) {
                $guided = array_values(array_filter($guided, fn (string $id): bool => isset($whole[$id])));
            }
            if (isset($held)) {
                $guided = array_values(array_filter($guided, fn (string $id): bool => isset($held[$id])));
            }
            $ranked = $this->merge($spelling, [$meaning, $pictures, $guided, $described, $termHits], [...$pinned, ...$subject], [1.0, 1.0, self::GUIDED_WEIGHT, self::DESCRIBED_WEIGHT, 1.0]);
            // The guides by meaning after those the words found, each once.
            $ranked = [...$ranked, ...array_values(array_diff(array_column($guides, 'id'), $ranked))];
            $perGroup ??= (int) Settings::get('search.results_per_group', $shopId);

            $groups = array_fill_keys(self::GROUPS, []);

            foreach ($ranked as $id) {
                $record = $index['records'][$id] ?? null;
                $type = $record['t'] ?? null;

                if ($record !== null && $without !== [] && $type === 'product' && HebrewSearch::holdsAny($record['title'].' '.($record['kw'] ?? ''), $without)) {
                    $this->trace?->left($id, 'without');

                    continue;
                }

                if ($record !== null && $type === 'product' && ! HebrewSearch::inRange($record['pr'] ?? null, $minPrice, $maxPrice)) {
                    $this->trace?->left($id, 'price');

                    continue;
                }

                if ($record !== null && isset($groups[$type])) {
                    $groups[$type][] = $record;
                }
            }

            // What was in stock last night ahead of what was not, otherwise in rank order.
            $groups['product'] = array_merge(
                array_values(array_filter($groups['product'], fn (array $r): bool => ($r['s'] ?? 0) === 1)),
                array_values(array_filter($groups['product'], fn (array $r): bool => ($r['s'] ?? 0) !== 1)),
            );

            // "עגילי חישוק זהב צהוב עם אבנים שקופות": the colours named, each with its part, weigh as in a
            // photo search: what agrees comes first, what says another metal or colour goes after the rest.
            $asked = Colours::parts([$priced]);
            $groups['product'] = self::byColour($groups['product'], $asked);
            // and, where looks matter, what its picture shows most is that colour before what has it as a detail: a black
            // T-shirt sold as "Black-White" (its print) is filed under white too, and came among the white ones (Vans)
            $groups['product'] = $asked !== [] && Looks::matter($shopId) ? self::byPictureColour($groups['product'], $asked) : $groups['product'];
            // A sentence no product holds whole ("חולצה לבנה עם כיס קטן בצד לגברים") is found by meaning and by picture, which
            // tell a white shirt with a pocket from a white t-shirt poorly: what says more of its words comes first ("כיס",
            // in a shirt's picture words), in stock and colour order kept within each (Story, 25.10)
            // a description, never a question about a kind ("איזה עץ לפרגולה?": the wood before a bracket that names wood
            // and pergola both) nor what the search already put first
            if ($scattered !== null && ! $asks && $pinned === [] && $subject === []) {
                $groups['product'] = self::bySaidWords($groups['product'], $scattered);
            }
            // "תיק לבן": a word of the search that opens the titles of a kind of product here ("תיק גב", "תיק צד") says the
            // kind asked for, and what is said with it ("לבן", "עם כיס") holds across every kind: only that kind is shown. A
            // white keychain is no white bag: none, and the drawer offers the AI. A kind alone ("מדף") keeps what is very
            // near it by meaning ("כוורת מדפים").
            $kinds = $read === [] && ! str_contains(HebrewSearch::normalize($worded), ' ') ? [] : self::askedKinds($index, $worded);
            // ...unless most of the store says it: "פטריה" opens a few titles of a mushroom store ("פטריית ריישי") and is
            // what every one of its formulas is made of ("מיקו דרים"); the store's subject is no kind among its products
            $kinds = array_filter($kinds, fn (int $opens, string $stem): bool => ! DescribedProducts::subject($shopId, (string) $index['hash'], $stem, $opens), ARRAY_FILTER_USE_BOTH);
            if ($kinds !== []) {
                $before = array_column($groups['product'], 'id');
                $groups['product'] = array_values(array_filter($groups['product'], fn (array $r): bool => self::ofKinds($r, $kinds)));
                $this->trace?->dropped($before, array_column($groups['product'], 'id'), 'kind');
            }
            $before = array_column($groups['product'], 'id');
            if ($read !== []) {
                // only what holds every value read: nothing else is shown as if it were asked for (the drawer then offers the AI)
                $groups['product'] = array_values(array_filter($groups['product'], fn (array $r): bool => array_all($read, fn (array $values, int $at): bool => self::holdsRead($r, $at, $values, (array) ($index['facets'] ?? [])))));
                $this->trace?->dropped($before, array_column($groups['product'], 'id'), 'facet');
            }

            // "הכי זול", "במבצע": filtered and sorted as asked, before any is cut.
            $before = array_column($groups['product'], 'id');
            $groups['product'] = HebrewSearch::applyActions($groups['product'], $actions);
            $this->trace?->dropped($before, array_column($groups['product'], 'id'), 'action');

            // The one product first, wherever the merge put it, and also when only its code found it; not one of another
            // kind than the search names (a keychain holding "תיק" and white is no white bag).
            if ($single !== null && $kinds !== [] && ! self::ofKinds($index['records'][$single['id']] ?? [], $kinds)) {
                $single = null;
            }
            if ($single !== null) {
                $groups['product'] = [
                    $index['records'][$single['id']],
                    ...array_values(array_filter($groups['product'], fn (array $r): bool => $r['id'] !== $single['id'])),
                ];
            }

            // Every product found, in order, for the quick filters and the pages beyond the first.
            $all = $only === null || in_array('product', $only, true) ? array_slice(array_column($groups['product'], 'id'), 0, (int) Settings::get('search.all_ids')) : [];
            $counts = array_map('count', $groups);
            $total = array_sum($counts);

            if ($count) {
                $this->counter->search($shopId, $query, $total, $visit);
            }

            foreach ($groups as $type => $records) {
                $groups[$type] = $only === null || in_array($type, $only, true)
                    ? array_map(fn (array $r): array => $this->present($r), array_slice($records, 0, $perGroup))
                    : [];
            }

            // With the choices, the category the words found first, as a way in of its own.
            $focus = $focus === [] ? null : ['options' => $focus, 'category' => array_intersect_key($groups['category'][0] ?? [], array_flip(['title', 'url', 'products'])) ?: null];

            // held: how many products hold every word as typed. None: what is shown is only what is nearest by meaning,
            // and the drawer says so, with the AI one press away, rather than showing it as if it were what was asked for.
            return ['query' => $query, 'unknown' => $unknown, 'held' => count(self::whole($spelling)), 'total' => $total, 'semantic' => $meaning !== [] || $pictures !== [] || $guides !== [], 'resolved' => $pinned !== [], 'subject' => $subject !== [], 'actions' => $actions['ids'], 'ids' => $all, 'single' => $single, 'focus' => $focus, 'groups' => $groups, 'counts' => $counts]
                // the words read by meaning as the store writes them ("מפוספס": "פסים"), for the drawer to show as it reads them
                + ($nearWords === [] ? [] : ['near' => array_map(fn (string $w, string $t): array => ['word' => $w, 'term' => $t], array_keys($nearWords), array_values($nearWords))]);
        });
    }

    /**
     * Products in the order given (the pictures' likeness), moved by how they look beside the product as their
     * pictures are described: the colour each shows most, and its tone (Colours::dominant; a pale yellow shirt's alike
     * are light ones before dark green ones), and the share of their described words together (print, material, a
     * detail; a long description is no closer for being long). A beige suede shoe's alike are the beige suede ones,
     * not black shoes whose description also names a white sole and a black line.
     *
     * @param  list<string>  $externalIds
     * @return list<string>
     */
    /**
     * What a question asked on a product's page wants of its alike besides being alike (SimilarWants), read against
     * the words the store says.
     *
     * @return array{words: list<string>, price: 'up'|'down'|null, much: bool}
     */
    public function similarWants(string $shopId, string $externalId, string $asked): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $externalId, $asked): array {
            $index = LoadedIndex::for($shopId);
            $records = array_filter((array) ($index['records'] ?? []), fn ($r, $key): bool => is_array($r) && str_starts_with((string) $key, 'p:'), ARRAY_FILTER_USE_BOTH);

            return SimilarWants::read($asked, $records, $records['p:'.$externalId] ?? null);
        });
    }

    /**
     * Of a product's alike, those that look like it by its picture's words: of the colour it shows most (Colours::dominant,
     * the same family) and printed as it is, or plain as it is. Its picture naming no colour (no words read): all of them.
     *
     * @param  list<string>  $externalIds
     * @return list<string>
     */
    private static function seenAlike(CatalogProduct $product, array $externalIds): array
    {
        $own = array_values((array) (RetrievalImage::query()->where('product_id', $product->id)->first(['caption_words'])?->caption_words ?? []));
        $colour = Colours::dominant($own, colourFirst: true);
        if ($colour === null || $externalIds === []) {
            return $externalIds;
        }
        $printed = self::patterned($own);
        $theirs = RetrievalImage::query()->whereIn('external_id', $externalIds)->get(['external_id', 'caption_words'])->keyBy('external_id');

        return array_values(array_filter($externalIds, function (string $id) use ($theirs, $colour, $printed): bool {
            $words = array_values((array) ($theirs->get($id)?->caption_words ?? []));

            return Colours::near($colour, Colours::dominant($words, colourFirst: true)) >= self::SAME_COLOUR && self::patterned($words) === $printed;
        }));
    }

    /** @param  list<mixed>  $words */
    private static function patterned(array $words): bool
    {
        return preg_match(self::PATTERNED, implode(' ', array_filter($words, 'is_string'))) === 1;
    }

    private static function bySeenWords(CatalogProduct $product, array $externalIds): array
    {
        if (count($externalIds) < 2) {
            return $externalIds;
        }

        $words = fn ($row): array => array_values(array_unique(array_filter(array_map(
            fn ($w): string => is_string($w) ? HebrewSearch::stem(HebrewSearch::normalize($w)) : '',
            array_merge(...array_map(fn ($w): array => is_string($w) ? explode(' ', $w) : [], (array) ($row?->caption_words ?? []))),
        ), fn (string $w): bool => mb_strlen($w) > 1)));
        $ownRow = RetrievalImage::query()->where('product_id', $product->id)->first(['caption_words']);
        $own = $words($ownRow);
        if ($own === []) {
            return $externalIds;
        }
        $ownColour = Colours::dominant(array_values((array) $ownRow->caption_words));

        $theirs = RetrievalImage::query()->whereIn('external_id', $externalIds)->get(['external_id', 'caption_words'])->keyBy('external_id');
        $count = count($externalIds);
        $score = [];
        foreach ($externalIds as $at => $id) {
            $row = $theirs->get($id);
            $other = $words($row);
            $together = count(array_unique([...$own, ...$other]));
            $share = $together === 0 ? 0.0 : count(array_intersect($own, $other)) / $together;
            $colour = Colours::near($ownColour, Colours::dominant(array_values((array) ($row?->caption_words ?? []))));
            $score[$id] = self::SEEN_ORDER * (1 - $at / $count) + self::SEEN_WORDS * $share + self::SEEN_COLOUR * $colour;
        }
        usort($externalIds, fn (string $a, string $b): int => $score[$b] <=> $score[$a]);

        return $externalIds;
    }

    /**
     * Whether a product is of a kind the search names: its title opens with it, or the store files it under it (its type,
     * categories or tags, BuildSearchIndex's kd): "נעלים לבנים" holds the white sneakers the store tags "נעליים".
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, int>  $kinds
     */
    private static function ofKinds(array $record, array $kinds): bool
    {
        return isset($kinds[self::headOf((string) ($record['title'] ?? ''))]) || array_intersect_key($kinds, array_flip((array) ($record['kd'] ?? []))) !== [];
    }

    /**
     * Of these alike, those of another colour than its own: where the store filters by colour or stone ("צבע", "אבן"),
     * none sharing a value of those with it; elsewhere none whose title names a colour its own names.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>|null  $own
     * @param  list<array<string, mixed>>  $facets
     * @return list<array<string, mixed>>
     */
    /** @param array<string, array<string, mixed>> $all every record of the index: how common a colour is in the store */
    private static function otherColour(array $records, ?array $own, array $facets, array $all = []): array
    {
        if ($own === null) {
            return $records;
        }
        // a colour filter only (a stone's colour among them, "צבע האבן"), never the stone or its cut: the diamonds beside
        // an amethyst are on nearly every ring. And of its colours only those few of the alike share: the small clear
        // stones around it, on most of the store's products, are no colour of its own (Rocks: "another colour" left no ring at all)
        $looks = array_keys(array_filter($facets, fn (array $f): bool => preg_match('/colou?r|צבע/iu', (string) ($f['k'] ?? '').' '.(string) ($f['l'] ?? '')) === 1));
        $codes = array_values(array_filter((array) ($own['f'] ?? []), fn ($code): bool => in_array(intdiv((int) $code, 1000), $looks, true)
            && count(array_filter($all, fn (array $r): bool => in_array($code, (array) ($r['f'] ?? []), true))) <= count($all) / 2));
        if ($codes !== []) {
            return array_values(array_filter($records, fn (array $r): bool => array_intersect($codes, (array) ($r['f'] ?? [])) === []));
        }
        $colours = fn (array $r): array => array_column(array_filter(Colours::mentions((string) ($r['title'] ?? '')), fn (array $m): bool => $m['group'] !== 'metal'), 'family');
        $mine = $colours($own);

        return $mine === [] ? $records : array_values(array_filter($records, fn (array $r): bool => array_intersect($mine, $colours($r)) === []));
    }

    private static function headOf(string $title): string
    {
        return HebrewSearch::head($title);
    }

    /**
     * The kinds a search names, as title stems: its words that open the titles of at least two products here and
     * not of most of them ("תיק" in "תיק לבן", "נעליים" for "נעלי סניקרס"). Counted once an index.
     *
     * @param  array{hash?: string, records: array<string, array<string, mixed>>}  $index
     * @return array<string, int> each stem and how many titles it opens
     */
    private static function askedKinds(array $index, string $query): array
    {
        static $heads = [];
        $key = (string) ($index['hash'] ?? spl_object_id((object) $index));
        if (! isset($heads[$key])) {
            $count = ['products' => 0, 'heads' => []];
            foreach ($index['records'] as $id => $record) {
                if (str_starts_with((string) $id, 'p:')) {
                    $count['products']++;
                    $head = self::headOf((string) ($record['title'] ?? ''));
                    $head !== '' && $count['heads'][$head] = ($count['heads'][$head] ?? 0) + 1;
                }
            }
            $heads = [$key => $count];
        }

        $asked = [];
        foreach (explode(' ', HebrewSearch::normalize($query)) as $word) {
            $stem = preg_match('/^[א-ת]{2,}$/u', $word) === 1 ? HebrewSearch::stem($word) : '';
            $n = $heads[$key]['heads'][$stem] ?? 0;
            if ($stem !== '' && $n >= 2 && $n <= $heads[$key]['products'] * self::KIND_MAX_SHARE) {
                $asked[$stem] = $n;
            }
        }

        return $asked;
    }

    /**
     * Products of the kind a question asks about: the categories whose name starts with its
     * subject ("עץ אורן", "עצים" for "עץ"; not "צבע לעץ"), what spelling already found of them
     * first, then the rest of them in stock first. At most SUBJECT_PRODUCTS record ids.
     *
     * @param  array{engine: array<string, mixed>, records: array<string, array<string, mixed>>}  $index
     * @param  list<array{id: string}>  $spelling
     * @return list<string>
     */
    private function subjectProducts(array $index, string $query, array $spelling): array
    {
        $subject = HebrewSearch::subject($index['engine'], $query);

        if ($subject === null) {
            return [];
        }

        $close = array_keys(HebrewSearch::closeWords($index['engine'], $subject));
        $categories = [];

        foreach ($index['records'] as $record) {
            $first = explode(' ', HebrewSearch::normalize((string) ($record['title'] ?? '')))[0];

            if (($record['t'] ?? null) === 'category' && $first !== '' && in_array($first, $close, true)) {
                $categories[] = substr((string) $record['id'], 2);
            }
        }

        if ($categories === []) {
            return [];
        }

        // A category that holds most of the store names nothing: "סיפור על השבי" in a bookshop read "סיפור" as its
        // "ספרים" and showed every book by its title after the books about captivity (Sela, 25.10).
        $inCategories = CatalogProduct::query()->active()->whereHas('categories', fn ($q) => $q->whereIn('external_id', $categories));
        $members = (clone $inCategories)->count();
        $names = array_map(fn (string $id): string => (string) ($index['records']['k:'.$id]['title'] ?? $id), $categories);
        if ($members > self::SUBJECT_MAX_PRODUCTS && $members > self::SUBJECT_MAX_SHARE * CatalogProduct::query()->active()->count()) {
            $this->trace?->step('subject', ['wide' => $names, 'members' => $members]);

            return [];
        }

        $members = $inCategories
            ->orderByDesc('in_stock')->orderBy('title')
            ->limit(200)->pluck('external_id')->map(fn ($id): string => 'p:'.$id)->all();
        $members = array_values(array_filter($members, fn (string $id): bool => isset($index['records'][$id])));
        $found = array_values(array_intersect(array_column($spelling, 'id'), $members));
        $subject = array_slice(array_values(array_unique([...$found, ...$members])), 0, self::SUBJECT_PRODUCTS);
        foreach ($this->trace === null ? [] : $subject as $id) {
            $this->trace->found($id, 'subject', ['categories' => $names]);
        }

        return $subject;
    }

    /**
     * Products like one in the results: by its picture when the shop's pictures are scanned, by
     * the meaning of its name and description otherwise. Both read vectors already stored, so no
     * model is asked; what was in stock last night comes first, and the product itself never.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @param  bool  $otherColour  "in another colour": its alike, never of its own colour or stone
     * @param  list<array<string, mixed>>|null  $others  filled with those of its kind and audience its look left out (another
     *                                                   colour, a print beside plain), in stock first: "maybe these" when few are alike
     */
    public function similar(string $shopId, string $externalId, int $limit, bool $otherColour = false, ?array $wants = null, ?array &$others = null): array
    {
        $wants = $wants !== null && SimilarWants::any($wants) ? $wants : null;
        $others = [];

        return $this->tenant->run($shopId, function () use ($shopId, $externalId, $limit, $otherColour, $wants, &$others): array {
            $index = LoadedIndex::for($shopId);
            $product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->first(['id', 'shop_id', 'title', 'payload']);

            if ($index === null || $product === null || $limit < 1) {
                return [];
            }

            // Read now, from what the store sells now: the pictures that look alike and the products that
            // mean the same, ranked together. More than shown are read: those of another kind are left out.
            // As the shop chose (search.similar_by): by content, by picture, or both. A store whose covers look
            // alike (a bundle of books beside another bundle) asks by content alone.
            $by = (string) Settings::get('search.similar_by', $shopId);
            // in another colour, a wider pool: most of the nearest are of its own colour, and they go
            $pool = $limit * ($otherColour || $wants !== null ? 12 : 4);
            $pool = $by === 'meaning' ? $pool : max($pool, self::LOOKS_POOL);
            $looks = $by === 'meaning' || ! $this->semantic->picturesReady() ? [] : array_map('strval', array_column($this->semantic->lookAlike($product->id, $pool), 'external_id'));
            $means = $by === 'picture' && $looks !== [] ? [] : array_map('strval', array_column($this->semantic->similarTo('product', $product->id, ['product'], $pool), 'external_id'));
            // The tags by meaning it shares with others, where the shop has them (Taxonomy): what it is about, not how its
            // description reads. Those that share them first, the meaning (and the picture) ordering them among themselves;
            // when they are enough, only they. "חטוף" is like "מפגש עם עצמי בגיהינום", not like "דרקולה".
            $tagged = app()->bound(RelatedByTags::class) ? array_column(app(RelatedByTags::class)->to($shopId, $externalId, $pool), 'external_id') : [];
            $rank = [];
            foreach ([[$tagged, self::TAG_SHARE], [$looks, 1.0], [$means, 1.0]] as [$list, $share]) {
                foreach ($list as $at => $id) {
                    $rank[(string) $id] = ($rank[(string) $id] ?? 0) + $share / (self::RRF_K + $at);
                }
            }
            arsort($rank);
            $ids = array_map('strval', array_keys($rank));
            // A store where looks matter (by picture, or both): what looks alike leads, in the pictures' own order among
            // its own kind, and what they did not find follows by the tags and the meaning. A grey sneaker's alike are
            // grey sneakers, not every shoe of its collection ("NEW COLLECTION", "גברים"). The tags lead in a store of content.
            $looksLead = $by !== 'meaning' && $looks !== [];
            if ($looksLead) {
                $ids = array_values(array_unique([...$looks, ...$ids]));
            }
            if ($tagged !== [] && ! $looksLead) {
                $sharing = array_flip(array_map('strval', $tagged));
                $first = array_values(array_filter($ids, fn (string $id): bool => isset($sharing[$id])));
                $ids = count($first) >= $limit ? $first : [...$first, ...array_values(array_filter($ids, fn (string $id): bool => ! isset($sharing[$id])))];
            }

            // Of its own kind, or none: a baby cream's nearest pictures may be blankets on the same white background.
            $ids = app(ProductKinds::class)->sameKind($product, array_map('strval', $ids));
            // Where looks matter, what its picture is described with shows most (its colour, its print, its material): a
            // leopard backpack's alike are leopard bags first, then the rest in the pictures' order
            // and they look like it, of its colour and plain beside plain, unless the question asks another colour or names
            // what it wants ("עם יהלום"): only kind made a striped, a floral and a denim shirt a brown overshirt's alike (Story)
            $ofKind = $ids;
            $ids = $looksLead && ! $otherColour && ($wants['words'] ?? []) === [] ? self::seenAlike($product, $ids) : $ids;
            // what its look left out, as the store sells them now: never the product itself, and still all the question asked
            $left = array_filter(array_map(fn (string $id): ?array => $id === $externalId ? null : ($index['records']['p:'.$id] ?? null), array_values(array_diff($ofKind, $ids))));
            $left = $wants === null ? array_values($left) : SimilarWants::apply(array_values($left), $wants, $index['records']['p:'.$externalId] ?? null);
            usort($left, fn (array $a, array $b): int => ($b['s'] ?? 0) <=> ($a['s'] ?? 0));
            $others = array_map(fn (array $r): array => $this->present($r), array_slice($left, 0, $limit));
            $ids = $looksLead ? self::bySeenWords($product, $ids) : $ids;

            $records = [];

            foreach ($ids as $id) {
                $record = $index['records']['p:'.$id] ?? null;

                if ($record !== null && (string) $id !== $externalId) {
                    $records[] = $record;
                }
            }

            // "in another colour" (Rocks: a ring like this amethyst one, with another stone): never one of its own colour
            if ($otherColour) {
                $records = self::otherColour($records, $index['records']['p:'.$externalId] ?? null, (array) ($index['facets'] ?? []), $index['records']);
            }
            // what else the question asked of them ("עם יהלום והרבה יותר יקרה"): only those that are all of it, or none
            if ($wants !== null) {
                $records = SimilarWants::apply($records, $wants, $index['records']['p:'.$externalId] ?? null);
            }
            usort($records, fn (array $a, array $b): int => ($b['s'] ?? 0) <=> ($a['s'] ?? 0));
            // in stock first within each: those that share its tags still before the rest
            if (isset($sharing)) {
                $records = [...array_filter($records, fn (array $r): bool => isset($sharing[substr((string) $r['id'], 2)])), ...array_filter($records, fn (array $r): bool => ! isset($sharing[substr((string) $r['id'], 2)]))];
            }
            // Its own colours (the metal, the stone): a yellow-gold ring's alike are yellow gold first. Only where the
            // shop filters by colour: a bookshop's covers are not a kind, and a black cover's alike are not black first.
            $own = $index['records']['p:'.$externalId] ?? null;
            $colours = array_filter((array) ($index['facets'] ?? []), fn (array $f): bool => preg_match(self::COLOUR_FILTER, (string) ($f['k'] ?? '').' '.(string) ($f['l'] ?? '')) === 1) !== [];
            // Where looks lead, its colour as its title says it ("טבעת זהב צהוב"): its picture's words name every colour on
            // it, a thin black line and a white sole too, and its pictures already found what is coloured like it.
            $ownText = $looksLead ? (string) ($own['title'] ?? '') : trim((string) ($own['title'] ?? '').' '.(string) ($own['kw'] ?? ''));
            $records = $own === null || ! $colours ? $records : self::byColour($records, Colours::parts([$ownText]));

            return array_map(fn (array $r): array => $this->present($r), array_slice($records, 0, $limit));
        });
    }

    /**
     * Products that look like a photo the shopper uploaded, most alike first, each with how alike
     * in percent. One picture embedding, or none when the same photo was searched before. The
     * photo is not kept; the search is counted, without it.
     *
     * @return array{total: int, groups: array{product: list<array<string, mixed>>}, searched: bool}
     */
    public function photo(string $shopId, string $mime, string $bytes, int $item = 0, ?bool $rank = null, bool $record = true, array $visit = []): array
    {
        // Whether a model orders the first products again: the shop's choice, unless the team's check asks.
        $rank ??= Features::enabled('search.photo_rank', $shopId);

        return $this->tenant->run($shopId, function () use ($shopId, $mime, $bytes, $item, $rank, $record, $visit): array {
            $index = LoadedIndex::for($shopId);
            $floor = (float) Settings::get('search.photo_min_similarity');
            $perGroup = (int) Settings::get('search.results_per_group', $shopId);

            // The shop's pictures nearest the photo: they order what the tags choose, and they speak
            // up when the reader's word disagrees with them.
            $hits = $index === null ? [] : $this->semantic->picturesNearPhoto($shopId, $mime, $bytes, self::PHOTO_ALL);

            // What the photo shows, in the shop's own categories and words.
            $look = $index === null ? PhotoLook::unread() : $this->photoTags->handle($shopId, $mime, $bytes, $hits, $item);
            // The separate things in the photo, for the shopper to choose which one they meant.
            $seen = $look->items === [] ? [] : ['items' => $look->items, 'item' => $look->item];
            $tags = $look->tags;
            // What the shopper is told was seen: the object, and the two details that tell it apart
            // most ("זהב צהוב", "אבן לבנה חלבית"), so a gold ring is not just "a ring with a stone".
            $told = array_slice(array_values(array_filter((array) $look->details, fn ($d): bool => is_string($d) && $d !== '' && ! str_contains((string) $look->object, $d))), 0, 2);
            $seen += $look->object === null ? [] : ['seen' => mb_substr(implode(' · ', [$look->object, ...$told]), 0, 120)];
            $cut = false;

            // A ring on a hand, a lamp in a room: the object alone, cut from the photo, is compared with
            // the shop's pictures too, and each product keeps the closer of the two likenesses.
            if ($look->box !== null && $hits !== [] && ($piece = self::cut($bytes, $look->box)) !== null) {
                $near = $this->semantic->picturesNearPhoto($shopId, 'image/jpeg', $piece, self::PHOTO_ALL);
                // Another thing in the photo was chosen: the whole photo is about the first one, so only its own cut counts.
                $hits = $look->item > 0 && $near !== [] ? $near : self::closer($hits, $near);
                $cut = true;
            } elseif ($look->item > 0) {
                // The whole photo is about the first thing in it, not the one chosen: its likeness would mislead.
                $hits = [];
            }

            // The photo was read and shows nothing the shop sells (a child, a landscape): nothing
            // looks like it, however close a white-background picture of a screw may score.
            if ($tags === []) {
                $record && $this->counter->photo($shopId, 0);
                $record && $this->keeper->handle($shopId, $bytes, $look, [], 0, visit: $visit);

                return ['total' => 0, 'groups' => ['product' => []], 'tags' => [], 'nothing' => true, 'searched' => true] + $seen;
            }

            // The reader was asked and could not make the photo out: say so. Pictures alike in colour
            // and light would be shown as if they were what the shopper photographed.
            if ($look->failed) {
                $record && $this->counter->photo($shopId, 0);
                $record && $this->keeper->handle($shopId, $bytes, $look, [], 0, visit: $visit);

                return ['total' => 0, 'groups' => ['product' => []], 'tags' => [], 'unclear' => true, 'searched' => true];
            }

            if ($tags !== null) {
                // A scene (a pergola, a deck) looks like no single product picture, so the picture
                // scores are flat and the nearest pictures are noise. The tags say what to show; the
                // picture only orders it. Every product carries which tags it belongs to, so the
                // shopper narrows by a tag without leaving the photo.
                // What the photo shows, against what each product's picture shows.
                // What tells it apart ("אבן אובלית בצבע תכלת"), not only the category's name: an aquamarine
                // ring is matched with the pictures described so, not with every gold ring.
                $words = $look->details !== [] ? [$look->object, ...$look->details] : array_column($tags, 'title');
                $content = app(PictureContent::class)->picturesNearWords($shopId, implode(', ', array_filter($words)), self::PHOTO_ALL);
                // what the reader said the object has not ("בלי הדפס" among its details) counts as what it lacks
                $lacks = array_values(array_unique([...$look->lacks, ...self::saidLacking($look->details)]));
                $products = $this->samePictureFirst($index, $hits, $this->taggedProducts($index, $tags, $hits, $shopId, $content, array_values(array_filter([$look->object, ...$look->details])), $lacks, $look->object));
                $products = $rank ? $this->ranker->handle($shopId, $bytes, $look->box, $products, self::described($look)) : $products;
                $record && $this->counter->photo($shopId, count($products));
                $record && $this->keeper->handle($shopId, $bytes, $look, $products, count($products), $cut, $visit);

                return ['total' => count($products), 'groups' => ['product' => $products], 'tags' => array_map(fn (array $t): array => array_diff_key($t, ['id' => 0, 'main' => 0]), $tags), 'searched' => true, 'close' => self::closeToPhoto($products, $shopId)] + $seen;
            }

            $hits = array_slice($hits, 0, max(48, (int) Settings::get('search.semantic_results')));
            $hits = array_values(array_filter($hits, fn (array $hit): bool => isset($index['records']['p:'.$hit['external_id']]) && $hit['similarity'] >= $floor));
            $products = [];

            foreach ($this->photoOrder($hits, $shopId) as $hit) {
                $products[] = $this->present($index['records']['p:'.$hit['external_id']]) + ['match' => (int) round(max(0, min(1, $hit['similarity'])) * 100)];
            }

            $products = array_slice($this->samePictureFirst($index, $hits, $products), 0, $perGroup);
            $products = $rank ? $this->ranker->handle($shopId, $bytes, $look->box, $products) : $products;
            $record && $this->counter->photo($shopId, count($products));
            $record && $this->keeper->handle($shopId, $bytes, $look, $products, count($products), $cut, $visit);

            return ['total' => count($products), 'groups' => ['product' => $products], 'tags' => [], 'searched' => $hits !== [] || $index !== null, 'close' => self::closeToPhoto($products, $shopId)] + $seen;
        });
    }

    /**
     * Whether the first product shown truly looks like the photo (its picture's likeness, else its match, at least
     * search.photo_close_look): only then is it "the most alike". A store with no olive plain tee shows its nearest
     * tees, said to be the nearest, not the most alike.
     *
     * @param  list<array<string, mixed>>  $products
     */
    private static function closeToPhoto(array $products, string $shopId): bool
    {
        $first = $products[0] ?? null;
        $likeness = $first === null ? null : ($first['look'] ?? $first['match'] ?? null);

        return $likeness !== null && (int) $likeness >= (int) round((float) Settings::get('search.photo_close_look', $shopId) * 100);
    }

    /**
     * What a photo's details say its object has not ("בלי הדפס", "ללא אבן", "without a hood"): those words.
     *
     * @param  list<string>  $details
     * @return list<string>
     */
    private static function saidLacking(array $details): array
    {
        $lacks = [];
        foreach ($details as $detail) {
            if (preg_match('/^\s*(?:בלי|ללא|without|no)\s+(.+)$/iu', (string) $detail, $m) === 1 && trim($m[1]) !== '') {
                $lacks[] = trim($m[1]);
            }
        }

        return $lacks;
    }

    /**
     * Products in three bands by how their own words agree with the colours wanted: agreeing first, then
     * those that say nothing either way, then those that say another metal or colour. The order inside
     * each band is kept: relevance first, colour on top of it.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  list<array{family: string, group: string, part: string|null, weight: float}>  $colours
     * @return list<array<string, mixed>>
     */
    /**
     * Products ordered by how many words of the search each says in its title and words (its tags, its picture's words),
     * a word in any of its forms ("לבנה" is "לבן", "בצד" is "צד", "כיסים" is "כיס"); the order before kept within each
     * count. Words a question wraps itself in say nothing.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private static function bySaidWords(array $records, string $query): array
    {
        $words = array_values(array_filter(array_unique(explode(' ', HebrewSearch::normalize($query))), fn (string $w): bool => mb_strlen($w) >= 2 && ! HebrewSearch::isFiller($w)));
        if (count($words) < 2 || count($records) < 2) {
            return $records;
        }
        $forms = array_map(function (string $w): array {
            $out = [$w];
            $bare = preg_match('/^[והבלמשכ][א-ת]{2,}$/u', $w) === 1 ? mb_substr($w, 1) : null;
            foreach (array_filter([$w, $bare]) as $f) {
                $out[] = $f;
                if (mb_strlen($f) > 3) {
                    $out[] = (string) preg_replace('/(ה|ת|ימ|ות)$/u', '', $f);
                }
            }
            // in English, one and many: "pocket" says "pockets", "pockets" says "pocket"
            if (preg_match('/^[a-z]{3,}$/', $w) === 1) {
                array_push($out, $w.'s', (string) preg_replace('/(es|s)$/', '', $w));
            }

            return array_values(array_unique(array_filter($out, fn (string $f): bool => mb_strlen($f) >= 2)));
        }, $words);

        $said = [];
        foreach ($records as $at => $record) {
            $text = (string) ($record['title'] ?? '').' '.(string) ($record['kw'] ?? '');
            // a colour counts as the product's own (mc), never a part's ("כפתורים שחורים" says no black shirt)
            $own = (array) ($record['mc'] ?? []);
            $said[$at] = count(array_filter($forms, fn (array $fs): bool => ($colour = HebrewSearch::colorOf($fs[0])) !== null && $own !== []
                ? in_array($colour, $own, true)
                : array_any($fs, fn (string $f): bool => HebrewSearch::says($text, $f))));
        }
        $order = array_keys($records);
        usort($order, fn (int $a, int $b): int => [$said[$b], $a] <=> [$said[$a], $b]);

        return array_map(fn (int $at): array => $records[$at], $order);
    }

    /**
     * Products whose picture shows most a colour asked for (Colours::dominant, a colour before a metal) first, those whose
     * picture words name no colour next, and those that show most another colour last; the order before kept within each.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  list<array{family: string, group: string, part: string|null, weight: float}>  $asked
     * @return list<array<string, mixed>>
     */
    private static function byPictureColour(array $records, array $asked): array
    {
        $families = array_flip(array_column($asked, 'family'));
        if (count($records) < 2) {
            return $records;
        }
        $head = array_slice($records, 0, self::PICTURE_COLOUR_READ);
        $words = RetrievalImage::query()->whereIn('external_id', array_map(fn (array $r): string => substr((string) $r['id'], 2), $head))->pluck('caption_words', 'external_id');
        $bands = [0 => [], 1 => [], 2 => []];
        foreach ($head as $record) {
            $shows = Colours::dominant(array_values((array) ($words[substr((string) $record['id'], 2)] ?? [])), colourFirst: true);
            $bands[$shows === null ? 1 : (isset($families[$shows['family']]) ? 0 : 2)][] = $record;
        }

        return [...$bands[0], ...$bands[1], ...$bands[2], ...array_slice($records, self::PICTURE_COLOUR_READ)];
    }

    private static function byColour(array $records, array $colours): array
    {
        if ($colours === [] || count($records) < 2) {
            return $records;
        }

        $bands = [2 => [], 1 => [], 0 => []];

        foreach ($records as $record) {
            $score = Colours::score($colours, trim((string) ($record['title'] ?? '').' '.(string) ($record['kw'] ?? '')));
            $bands[$score >= self::COLOUR_AGREES ? 2 : ($score <= self::COLOUR_DISAGREES ? 0 : 1)][] = $record;
        }

        return [...$bands[2], ...$bands[1], ...$bands[0]];
    }

    /** What the reader saw, in words, for the model that orders: the object, its details, what it lacks. */
    private static function described(PhotoLook $look): string
    {
        return trim(implode(' ', array_filter([
            $look->object === null ? null : 'The shopper means: '.$look->object.'.',
            $look->details === [] ? null : 'Seen: '.implode(', ', $look->details).'.',
            $look->lacks === [] ? null : 'It has no: '.implode(', ', $look->lacks).'.',
        ])));
    }

    /**
     * The object cut from the photo, with a margin around it, as a JPEG; null when the photo cannot
     * be read as an image or the cut would be too small to say anything.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     */
    private static function cut(string $bytes, array $box): ?string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        [$width, $height] = [imagesx($image), imagesy($image)];
        [$left, $top, $right, $bottom] = $box;
        $padX = ($right - $left) * self::CUT_MARGIN;
        $padY = ($bottom - $top) * self::CUT_MARGIN;
        $x = (int) max(0, floor(($left - $padX) * $width));
        $y = (int) max(0, floor(($top - $padY) * $height));
        $w = (int) min($width - $x, ceil(($right - $left + 2 * $padX) * $width));
        $h = (int) min($height - $y, ceil(($bottom - $top + 2 * $padY) * $height));

        if ($w < self::CUT_MIN_PIXELS || $h < self::CUT_MIN_PIXELS) {
            return null;
        }

        $cut = imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);

        if ($cut === false) {
            return null;
        }

        // A thin band on a finger is a few dozen pixels: scaled up, the picture model sees a ring, not a smudge.
        if (max($w, $h) < self::CUT_UPSCALE_BELOW) {
            $scaled = $w >= $h ? imagescale($cut, self::CUT_UPSCALE_TO, -1, IMG_BICUBIC) : imagescale($cut, (int) round(self::CUT_UPSCALE_TO * $w / $h), self::CUT_UPSCALE_TO, IMG_BICUBIC);

            if ($scaled !== false) {
                $cut = $scaled;
            }
        }

        ob_start();
        imagejpeg($cut, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Two lists of likenesses as one: each product with the closer of its two, most alike first.
     *
     * @param  list<array{external_id: string, similarity: float}>  $a
     * @param  list<array{external_id: string, similarity: float}>  $b
     * @return list<array{external_id: string, similarity: float}>
     */
    private static function closer(array $a, array $b): array
    {
        $best = [];

        foreach ([...$a, ...$b] as $hit) {
            $id = (string) $hit['external_id'];
            $best[$id] = max($best[$id] ?? -1.0, (float) $hit['similarity']);
        }

        arsort($best);

        return array_map(fn (string $id, float $similarity): array => ['external_id' => $id, 'similarity' => $similarity], array_map('strval', array_keys($best)), array_values($best));
    }

    /**
     * The shop's own picture, photographed or saved and uploaded again, is the product itself:
     * whatever the reader called it and whatever tags it chose, a picture this alike comes first.
     *
     * @param  array{engine: array<string, mixed>, records: array<string, array<string, mixed>>}  $index
     * @param  list<array{external_id: string, similarity: float}>  $hits  nearest first
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function samePictureFirst(array $index, array $hits, array $products): array
    {
        $floor = (float) Settings::get('search.photo_same_similarity');
        $same = [];

        // Only the nearest picture can be the same one; close is not the same.
        foreach (array_slice($hits, 0, 1) as $hit) {
            $record = $index['records']['p:'.$hit['external_id']] ?? null;

            if ($record !== null && $hit['similarity'] >= $floor) {
                $same[] = $this->present($record) + ['match' => (int) round(min(1, $hit['similarity']) * 100), 'same' => true];
            }
        }

        if ($same === []) {
            return $products;
        }

        $ids = array_column($same, 'external_id');

        return [...$same, ...array_values(array_filter($products, fn (array $p): bool => ! in_array($p['external_id'], $ids, true)))];
    }

    /**
     * The products a tagged photo is about: each tag's products (a category's own, or what its
     * words find), in stock when the shop asks for it, ordered by how alike their picture is to
     * the photo and varied by brand. Each carries `tags`, the positions of the tags it belongs to.
     *
     * @param  array{engine: array<string, mixed>, records: array<string, array<string, mixed>>}  $index
     * @param  list<array{title: string, kind: string, id?: string}>  $tags
     * @param  list<array{external_id: string, similarity: float}>  $hits
     * @return list<array<string, mixed>>
     */
    private function taggedProducts(array $index, array $tags, array $hits, string $shopId, array $content = [], array $description = [], array $lacks = [], ?string $object = null): array
    {
        $members = [];
        // What the reader's own words for the object name: of the kind photographed, whatever category it chose.
        [$named, $specific] = self::objectNamed($index, $object);
        $objectNamed = array_flip($named);
        foreach (array_keys($objectNamed) as $id) {
            $members[$id] ??= [];
        }

        foreach ($tags as $at => $tag) {
            $ids = isset($tag['id'])
                ? CatalogProduct::query()->active()
                    ->whereHas('categories', fn ($q) => $q->where('external_id', substr((string) $tag['id'], 2)))
                    // The whole category: the likeness below picks from all of it. A cap here took the first
                    // rows the database gave, and the right ring could be outside them.
                    ->limit(self::PHOTO_CATEGORY_ALL)->pluck('external_id')->map(fn ($id): string => (string) $id)->all()
                : array_map(fn (array $hit): string => substr($hit['id'], 2), array_slice(array_values(array_filter(
                    HebrewSearch::search($index['engine'], $tag['title']),
                    fn (array $hit): bool => str_starts_with($hit['id'], 'p:'),
                )), 0, self::PHOTO_PER_TAG));

            foreach ($ids as $id) {
                if (isset($index['records']['p:'.$id])) {
                    $members[$id][] = $at;
                }
            }
        }

        if ($members === []) {
            return [];
        }

        // Looks and content together: each picture's visual likeness and its description's, weighed by
        // retrieval.content_weight; a picture with only one of them is judged by that one.
        $looks = array_column($hits, 'similarity', 'external_id');
        $about = array_column($content, 'similarity', 'external_id');
        $weight = (float) Settings::get('retrieval.content_weight');
        // A shop of tools (search.photo_match = function): what the picture shows counts over how it looks, so a
        // yellow pair of snips is not "like" a yellow screwdriver. A shop of clothes or jewelry (look) keeps the balance.
        if ((string) Settings::get('search.photo_match', $shopId) === 'function') {
            $weight = max($weight, self::PHOTO_FUNCTION_WEIGHT);
        }
        $similarity = [];
        foreach (array_unique([...array_keys($looks), ...array_keys($about)]) as $id) {
            $v = $looks[$id] ?? null;
            $c = $about[$id] ?? null;
            $similarity[$id] = $v !== null && $c !== null ? (1 - $weight) * $v + $weight * $c : (float) ($v ?? $c);
        }
        // The shop's own words for what the reader described ("פס", "חלקה", "סוליטר"): a product named so is
        // closer than its picture alone says. A word most candidates share ("זהב") weighs little.
        $named = $this->namedLike($index, array_keys($members), $description);
        $wordsWeight = (float) Settings::get('search.photo_words_weight', $shopId);
        $rows = CatalogProduct::query()->whereIn('external_id', array_keys($members))->get(['external_id', 'brand', 'in_stock'])->keyBy('external_id');
        // What the object lacks (a plain band has no stone): a product whose name or picture has it goes after the rest.
        $lacking = $this->holding($index, array_keys($members), $lacks);
        // The colour of each part the reader saw (the metal, the stone, the shirt, the pocket), against each
        // product's own words: a yellow-gold ring with a white stone before a white-gold one however alike.
        $colours = Colours::parts($description);
        $colourWeight = (float) Settings::get('search.photo_colour_weight', $shopId);
        $tint = [];
        // what each picture is described with too ("אבן מרכזית ירוקה"): a title names its stone, not always its colour
        $seenWords = $colours === [] ? collect() : RetrievalImage::query()->whereIn('external_id', array_map('strval', array_keys($members)))->pluck('caption_words', 'external_id');
        foreach (array_keys($members) as $id) {
            $record = $index['records']['p:'.$id] ?? [];
            $tint[$id] = $colours === [] ? 0.0 : Colours::score($colours, trim((string) ($record['title'] ?? '').' '.(string) ($record['kw'] ?? '').' '.implode(', ', array_map('strval', (array) ($seenWords[(string) $id] ?? [])))));
        }
        $candidates = array_map(fn ($id): array => ['external_id' => (string) $id, 'similarity' => (float) ($similarity[$id] ?? 0.0) + $wordsWeight * ($named[$id] ?? 0.0) + $colourWeight * $tint[$id] - (isset($lacking[$id]) ? self::LACKS_PENALTY : 0.0)], array_keys($members));

        if ((bool) Settings::get('search.photo_in_stock_only', $shopId)) {
            $stocked = array_values(array_filter($candidates, fn (array $c): bool => (bool) $rows->get($c['external_id'])?->in_stock));
            $candidates = $stocked !== [] ? $stocked : $candidates;
        }

        // What the shopper photographed first (the main tag's products), then by likeness, then by
        // how many of the photo's tags a product shares. Likeness alone would put a scanned tool box
        // above a drill whose picture is not scanned yet.
        $main = array_keys(array_filter($tags, fn (array $t): bool => ($t['main'] ?? false) === true));
        $isMain = fn (array $c): int => isset($objectNamed[$c['external_id']]) || array_intersect($members[$c['external_id']], $main) !== [] ? 1 : 0;
        // Only the kind photographed, when the shop has enough of it: a screwdriver's photo shows screwdrivers,
        // however alike a yellow pair of snips or a drill looks (and nothing else can be lifted above them later)
        $ofKind = array_values(array_filter($candidates, fn (array $c): bool => $isMain($c) === 1));
        if (count($ofKind) >= self::PHOTO_KIND_MIN) {
            $candidates = $ofKind;
        }
        // When the main category holds none of what the object's own words name, the category was
        // chosen by a shared word: "סטים" (power-tool kits) for "סט ביטים". Then the bit sets come
        // first. When it holds some ("טבעת" among the rings), the picture orders them as before.
        $missed = $objectNamed !== [] && array_filter(array_keys($objectNamed), fn ($id): bool => array_intersect($members[$id] ?? [], $main) !== []) === [];
        // "טבעת חותם": the words name a kind narrower than the category (rings), so the signet rings
        // come before the other rings, and their pictures order them.
        $missed = $missed || $specific;
        $byObject = fn (array $c): int => $missed && isset($objectNamed[$c['external_id']]) ? 1 : 0;
        usort($candidates, fn (array $a, array $b): int => $byObject($b) <=> $byObject($a) ?: $isMain($b) <=> $isMain($a) ?: $b['similarity'] <=> $a['similarity'] ?: count($members[$b['external_id']]) <=> count($members[$a['external_id']]));
        $brandOf = fn (array $c): string => mb_strtolower(trim((string) $rows->get($c['external_id'])?->brand)) ?: 'id:'.$c['external_id'];

        $out = [];
        foreach (array_slice($this->varied($candidates, $brandOf), 0, self::PHOTO_TAGGED) as $c) {
            $id = $c['external_id'];
            $out[] = $this->present($index['records']['p:'.$id]) + array_filter([
                'match' => $c['similarity'] > 0 ? (int) round(min(1, $c['similarity']) * 100) : null,
                'tags' => $members[$id],
                // Why it is here, for the photo log: how alike its picture looks, how well what its picture
                // shows matches the details, and whether it is of the kind photographed.
                'look' => isset($looks[$id]) ? (int) round(max(0, min(1, $looks[$id])) * 100) : null,
                'about' => isset($about[$id]) ? (int) round(max(0, min(1, $about[$id])) * 100) : null,
                'words' => isset($named[$id]) && $named[$id] > 0 ? (int) round($named[$id] * 100) : null,
                'lacks' => isset($lacking[$id]) ? true : null,
                'colour' => ($tint[$id] ?? 0.0) !== 0.0 ? (int) round($tint[$id] * 100) : null,
                'kind' => $isMain($c) === 1,
            ], fn ($v): bool => $v !== null);
        }

        return $out;
    }

    /**
     * The products the reader's words for the object name, as the store's search reads them: the
     * whole description first, then its first words, until some product holds all of them. "סט
     * ביטים לבורג בבסיס פלסטיק אדום" names the bit sets ("סט ביטים"), whichever category the
     * reader chose. One short word alone ("סט") names nothing; at most OBJECT_NAMED products.
     *
     * @param  array{engine: array<string, mixed>, records: array<string, array<string, mixed>>}  $index
     * @return array{0: list<string>, 1: bool} external ids, and whether the words name a kind narrower than the noun
     */
    private static function objectNamed(array $index, ?string $object): array
    {
        $words = preg_split('/\s+/u', trim((string) $object), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        for ($n = min(count($words), self::OBJECT_WORDS); $n >= 1; $n--) {
            $phrase = implode(' ', array_slice($words, 0, $n));

            if ($n === 1 && mb_strlen(HebrewSearch::normalize($phrase)) < 3) {
                break;
            }

            $ids = [];
            foreach (HebrewSearch::search($index['engine'], $phrase) as $hit) {
                if ($hit['full'] && str_starts_with($hit['id'], 'p:') && isset($index['records'][$hit['id']])) {
                    $ids[] = substr($hit['id'], 2);
                }
            }

            if ($ids !== []) {
                // Two words of three letters or more ("טבעת חותם", not "טבעת עם"): a kind of its own.
                $specific = count(array_filter(array_slice($words, 0, $n), fn (string $w): bool => mb_strlen(HebrewSearch::normalize($w)) >= 3)) >= 2;

                return [array_slice($ids, 0, self::OBJECT_NAMED), $specific];
            }
        }

        return [[], false];
    }

    /**
     * How much of the description's words each product's name and keywords hold, 0 to 1, weighed by
     * how few of the candidates hold each word. The description's head noun (the kind) counts for
     * nothing: every candidate is of that kind. A word is held as any of its forms, or as a longer or
     * shorter spelling of it (חלק, חלקה).
     *
     * @param  array{engine: array<string, mixed>, records: array<string, array<string, mixed>>}  $index
     * @param  list<string>  $ids
     * @param  list<string>  $description  the object first, then what tells it apart
     * @return array<string, float>
     */
    private function namedLike(array $index, array $ids, array $description): array
    {
        $wanted = [];
        $boost = [];

        foreach ($description as $at => $phrase) {
            foreach (array_slice(PhotoPlacer::words((string) $phrase), $at === 0 ? 1 : 0) as $forms) {
                $wanted[$forms[0]] = $forms;
                // The detail that tells the object apart most ("אותיות על לוח שחור") counts twice.
                $boost[$forms[0]] = max($boost[$forms[0]] ?? 1, $at === 1 ? self::KEY_DETAIL_WEIGHT : 1);
            }
        }

        if ($wanted === [] || $ids === []) {
            return [];
        }

        $held = [];

        foreach ($ids as $id) {
            $record = $index['records']['p:'.$id] ?? [];
            $own = self::withoutPrefix(PhotoPlacer::words(trim((string) ($record['title'] ?? '').' '.(string) ($record['kw'] ?? ''))));

            foreach ($wanted as $key => $forms) {
                foreach ($own as $word) {
                    if (self::sameWord($word, $forms)) {
                        $held[$key][$id] = true;
                        break;
                    }
                }
            }
        }

        $weights = [];

        foreach ($wanted as $key => $forms) {
            $weights[$key] = (1 - count($held[$key] ?? []) / count($ids)) * $boost[$key];
        }

        $total = array_sum($weights);
        $out = [];

        if ($total <= 0) {
            return [];
        }

        foreach ($ids as $id) {
            $score = 0.0;

            foreach ($weights as $key => $weight) {
                $score += isset($held[$key][$id]) ? $weight : 0;
            }

            $out[$id] = $score / $total;
        }

        return $out;
    }

    /**
     * A title's words, each also without the one letter Hebrew puts before a word (ה, ו, ב, ל, מ, ש, כ):
     * "עם האות M" holds "אות". Only words long enough to stay a word without it.
     *
     * @param  list<list<string>>  $words
     * @return list<list<string>>
     */
    private static function withoutPrefix(array $words): array
    {
        foreach ($words as $i => $forms) {
            foreach ($forms as $form) {
                if (mb_strlen($form) >= 3 && preg_match('/^[הובלמשכ]/u', $form) === 1) {
                    $words[$i][] = mb_substr($form, 1);
                }
            }
            $words[$i] = array_values(array_unique($words[$i]));
        }

        return $words;
    }

    /**
     * The candidates whose name or keywords (their picture's words among them) hold any of the words
     * the object lacks, as the typed search keeps "בלי יהלומים" out.
     *
     * @param  array{engine: array<string, mixed>, records: array<string, array<string, mixed>>}  $index
     * @param  list<string>  $ids
     * @param  list<string>  $lacks
     * @return array<string, true>
     */
    private function holding(array $index, array $ids, array $lacks): array
    {
        $stems = $lacks === [] ? [] : HebrewSearch::without('בלי '.implode(' בלי ', $lacks))[1];
        $out = [];

        if ($stems === []) {
            return [];
        }

        foreach ($ids as $id) {
            $record = $index['records']['p:'.$id] ?? [];

            if (HebrewSearch::holdsAny((string) ($record['title'] ?? '').' '.(string) ($record['kw'] ?? ''), $stems)) {
                $out[(string) $id] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $a  a word's forms
     * @param  list<string>  $b  another's
     */
    private static function sameWord(array $a, array $b): bool
    {
        if (array_intersect($a, $b) !== []) {
            return true;
        }

        foreach ($a as $x) {
            foreach ($b as $y) {
                if (mb_strlen($x) >= 3 && mb_strlen($y) >= 3 && (str_starts_with($x, $y) || str_starts_with($y, $x))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What a photo shows, in the order a shopper wants it. Picture scores sit close together and
     * favour a brand's colours, so the score alone shows six Makita tools and a box of screws.
     *
     *   1. kind      products in the categories of the best matches, whatever their brand,
     *                then what is only close to the best match (search.photo_relative_gap)
     *   2. variety   no more than two of one brand in a row while other brands are left
     *   3. stock     only what is in stock (search.photo_in_stock_only), unless nothing is
     *
     * @param  list<array{external_id: string, similarity: float}>  $hits  most alike first
     * @return list<array{external_id: string, similarity: float}>
     */
    private function photoOrder(array $hits, string $shopId): array
    {
        if ($hits === []) {
            return [];
        }

        $products = CatalogProduct::query()->whereIn('external_id', array_column($hits, 'external_id'))
            ->with('categories:id')->get(['id', 'external_id', 'brand', 'in_stock'])->keyBy('external_id');
        $categoriesOf = fn (array $hit): array => $products->get($hit['external_id'])?->categories->pluck('id')->all() ?? [];

        usort($hits, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
        $best = $hits[0]['similarity'];
        $close = $best - (float) Settings::get('search.photo_relative_gap');
        $kind = array_unique(array_merge(...array_map($categoriesOf, array_slice($hits, 0, 3))));

        $sameKind = array_values(array_filter($hits, fn (array $hit): bool => array_intersect($categoriesOf($hit), $kind) !== []));
        $onlyClose = array_values(array_filter($hits, fn (array $hit): bool => $hit['similarity'] >= $close && array_intersect($categoriesOf($hit), $kind) === []));

        if ((bool) Settings::get('search.photo_in_stock_only', $shopId)) {
            $inStock = fn (array $hit): bool => (bool) $products->get($hit['external_id'])?->in_stock;
            $stocked = array_values(array_filter($sameKind, $inStock));
            $stockedClose = array_values(array_filter($onlyClose, $inStock));

            if ($stocked !== [] || $stockedClose !== []) {
                [$sameKind, $onlyClose] = [$stocked, $stockedClose];
            }
        }

        $brandOf = fn (array $hit): string => mb_strtolower(trim((string) $products->get($hit['external_id'])?->brand)) ?: 'id:'.$hit['external_id'];

        return array_merge($this->varied($sameKind, $brandOf), $this->varied($onlyClose, $brandOf));
    }

    /**
     * The same hits, most alike first, but never a third of one brand in a row while another
     * brand is still waiting.
     *
     * @param  list<array{external_id: string, similarity: float}>  $hits
     * @return list<array{external_id: string, similarity: float}>
     */
    private function varied(array $hits, callable $brandOf): array
    {
        $out = [];

        while ($hits !== []) {
            $last = array_map($brandOf, array_slice($out, -2));
            $repeat = count($last) === 2 && $last[0] === $last[1] ? $last[0] : null;
            $at = 0;

            if ($repeat !== null) {
                foreach ($hits as $i => $hit) {
                    if ($brandOf($hit) !== $repeat) {
                        $at = $i;
                        break;
                    }
                }
            }

            $out[] = $hits[$at];
            array_splice($hits, $at, 1);
        }

        return $out;
    }

    /**
     * Product ids found by meaning, nearest first, and the guides and pages the same one question found
     * (their own group, see guides()).
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return array{products: list<string>, content: list<array{external_id: string, similarity: float}>}
     */
    /**
     * A code the store has and the index does not (a product added since it was built): the index is
     * built again in the background, at most every few minutes, so the next search finds it.
     *
     * @param  array<string, mixed>  $index
     */
    private function missingCode(string $shopId, string $query, array $index): void
    {
        $code = HebrewSearch::code($query);

        if (isset($index['codes'][$code])) {
            return;
        }

        $known = CatalogProduct::query()->whereNull('removed_at')
            ->where(fn ($q) => $q->where('sku', trim($query))->orWhere('title', 'like', '%'.trim($query).'%'))
            ->limit(20)->pluck('external_id')->map(fn ($id): string => 'p:'.$id);

        if ($known->contains(fn (string $id): bool => ! isset($index['records'][$id])) && Cache::add('search:missing-code:'.$shopId, true, now()->addMinutes(5))) {
            dispatch(fn () => app(BuildSearchIndex::class)->handle($shopId, RunTrigger::System));
        }
    }

    /** @param list<string> $spelled the products the spelling found: they keep the usual floor, anything else needs to be very near when they are a few */
    private function meaning(string $shopId, string $query, array $records, array $spelled = []): array
    {
        if (! Features::enabled('search.semantic', $shopId) || mb_strlen($query) < (int) Settings::get('search.semantic_min_chars')) {
            $this->trace?->step('meaning', ['off' => true]);

            return ['products' => [], 'content' => [], 'best' => 0.0];
        }

        $limit = (int) Settings::get('search.semantic_results');
        $usual = (float) Settings::get('search.semantic_min_similarity', $shopId);
        $strict = max($usual, (float) Settings::get('search.meaning_only_min_similarity', $shopId));
        $found = array_flip($spelled);
        $floor = fn (string $id): float => count($found) >= self::SPELLED_ENOUGH && ! isset($found[$id]) ? $strict : $usual;
        // best: how near the nearest product is, whatever the floor
        $out = ['products' => [], 'content' => [], 'best' => 0.0];
        // the nearest that stayed under the floor, for the check screen: what a lower floor would have let in
        $below = [];

        // the words as written, final letters at their ends ("לישון", never "לישונ"): a vector reads words, and a word
        // without its final letter is no word (a shop's sleep product fell from .45 to .18)
        foreach ($this->semantic->nearText($shopId, HebrewSearch::withFinals($query), ['product', 'content'], $limit) as $hit) {
            if ($hit['source'] !== 'product') {
                $out['content'][] = ['external_id' => (string) $hit['external_id'], 'similarity' => (float) $hit['similarity']];

                continue;
            }

            $id = 'p:'.$hit['external_id'];
            $out['best'] = max($out['best'], (float) $hit['similarity']);

            if ($hit['similarity'] >= $floor($id) && isset($records[$id])) {
                $out['products'][] = $id;
                $this->trace?->found($id, 'meaning', ['similarity' => round((float) $hit['similarity'], 3)]);
            } elseif ($this->trace !== null && isset($records[$id]) && count($below) < 5) {
                $below[] = ['id' => $id, 'title' => (string) $records[$id]['title'], 'similarity' => round((float) $hit['similarity'], 3), 'floor' => $floor($id)];
            }
        }

        $this->trace?->step('meaning', ['best' => round($out['best'], 3), 'floor' => $usual, 'strict' => $strict, 'strict_used' => count($found) >= self::SPELLED_ENOUGH, 'below' => $below]);

        return $out;
    }

    /**
     * Guides and pages found by meaning, the nearest first, each a little less near the older it is: a
     * guide about the words, not one a little like anything the store sells. Only where the shop wants
     * guides by meaning at all; otherwise a guide must hold the words.
     *
     * @param  list<array{external_id: string, similarity: float}>  $near  what meaning() found
     * @param  array<string, array<string, mixed>>  $records
     * @return list<array{id: string, similarity: float}>
     */
    private function guides(string $shopId, array $near, array $records): array
    {
        if ($near === [] || ! Features::enabled('search.content_by_meaning', $shopId)) {
            return [];
        }

        $floor = max((float) Settings::get('search.semantic_min_similarity', $shopId), (float) Settings::get('search.content_min_similarity', $shopId));
        $hits = [];

        foreach ($near as $hit) {
            $id = 'c:'.$hit['external_id'];

            if ($hit['similarity'] >= $floor && isset($records[$id])) {
                $hits[$hit['external_id']] = ['id' => $id, 'similarity' => (float) $hit['similarity']];
            }
        }

        if ($hits === []) {
            return [];
        }

        // News, a review or a buying guide of years ago weighs a little less than this year's on the same
        // subject: models and prices change. How to join boards to a deck does not; nor does a guide whose
        // kind the reading has not said.
        $perYear = (float) Settings::get('search.content_age_penalty');
        $contents = CatalogContent::query()->whereIn('external_id', array_map('strval', array_keys($hits)))->get(['id', 'external_id', 'source_updated_at']);
        $kinds = EnrichmentFact::query()->current()->where('status', FactStatus::Approved)->where('kind', FactKind::ContentKind)
            ->whereIn('content_id', $contents->pluck('id'))->pluck('value_text', 'content_id');
        $dated = $contents->filter(fn (CatalogContent $c): bool => in_array($kinds[$c->id] ?? null, self::AGEING_KINDS, true))->pluck('source_updated_at', 'external_id');
        foreach ($hits as $external => &$hit) {
            $at = $dated[(string) $external] ?? null;
            $years = $at === null ? 0.0 : max(0.0, now()->diffInDays($at, true) / 365);
            $hit['similarity'] -= min(3.0, $years) * $perYear;
        }
        unset($hit);

        $hits = array_values($hits);
        usort($hits, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return $hits;
    }

    /**
     * The products the nearest guides name or were matched to (EnrichmentContentProduct), in the guide's
     * own order: what a shopper asking about the need is likely to want.
     *
     * @param  list<array{id: string, similarity: float}>  $guides
     * @param  array<string, array<string, mixed>>  $records
     * @return list<string>
     */
    private function guided(array $guides, array $records): array
    {
        $externals = array_map(fn (array $g): string => substr($g['id'], 2), array_slice($guides, 0, self::GUIDES_FOR_PRODUCTS));
        $contents = CatalogContent::query()->whereIn('external_id', $externals)->pluck('id', 'external_id');
        $ids = [];

        foreach ($externals as $external) {
            if (! isset($contents[$external])) {
                continue;
            }

            $linked = EnrichmentContentProduct::query()->where('content_id', $contents[$external])->orderBy('rank')
                ->with('product:id,external_id,removed_at')->limit(self::GUIDED_PRODUCTS)->get();

            foreach ($linked as $link) {
                $id = $link->product !== null && $link->product->removed_at === null ? 'p:'.$link->product->external_id : null;

                if ($id !== null && isset($records[$id]) && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $this->trace?->found($id, 'guide', ['guide' => (string) ($records['c:'.$external]['title'] ?? '')]);
                }
            }
        }

        return $ids;
    }

    /**
     * Products whose picture matches the words, nearest first: "חולצת פסים" finds striped shirts
     * whose names never say so. Only where the shop's pictures are indexed.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return list<string>
     */
    private function pictures(string $shopId, string $query, array $records): array
    {
        // a store where looks do not matter finds nothing for words by its pictures (Looks)
        if (! Features::enabled('search.pictures', $shopId) || ! Looks::matter($shopId) || mb_strlen($query) < (int) Settings::get('search.semantic_min_chars')) {
            return [];
        }

        $floor = (float) Settings::get('search.picture_min_similarity');
        $ids = [];

        foreach ($this->semantic->picturesNearText($shopId, HebrewSearch::withFinals($query), (int) Settings::get('search.semantic_results')) as $hit) {
            $id = 'p:'.$hit['external_id'];

            if ($hit['similarity'] >= $floor && isset($records[$id])) {
                $ids[] = $id;
                $this->trace?->found($id, 'picture', ['similarity' => round((float) $hit['similarity'], 3)]);
            }
        }

        return $ids;
    }

    /**
     * What the night resolved for this exact query, when a shopper's search found nothing: the
     * products and guides a model matched and a model of another family accepted. Shown first.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return list<string>
     */
    private function resolved(string $query, array $records): array
    {
        $resolution = SearchResolution::query()->where('query_hash', hash('sha256', $query))->where('status', SearchResolution::RESOLVED)->first();

        if ($resolution === null) {
            return [];
        }

        $ids = array_merge(
            array_map(fn ($id): string => 'p:'.$id, (array) $resolution->products),
            array_map(fn ($id): string => 'c:'.$id, (array) $resolution->content),
        );

        return array_values(array_filter($ids, fn (string $id): bool => isset($records[$id])));
    }

    /**
     * @param  list<array{id: string, title: string, score: float, exact: bool}>  $spelling
     * @param  list<list<string>>  $others  ranked ids by meaning, by picture, by the guides
     * @param  list<string>  $pinned  what the night resolved for this query, first of all
     * @param  list<float>  $weights  each of $others' weight, 1 when not given
     * @return list<string>
     */
    private function merge(array $spelling, array $others, array $pinned = [], array $weights = []): array
    {
        $exact = [];
        $scores = [];

        foreach ($spelling as $rank => $hit) {
            if ($hit['exact']) {
                $exact[] = $hit['id'];

                continue;
            }

            $scores[$hit['id']] = ($scores[$hit['id']] ?? 0) + 1 / (self::RRF_K + $rank);
        }

        foreach ($others as $at => $list) {
            foreach ($list as $rank => $id) {
                if (! in_array($id, $exact, true)) {
                    $scores[$id] = ($scores[$id] ?? 0) + ($weights[$at] ?? 1.0) / (self::RRF_K + $rank);
                }
            }
        }

        arsort($scores);

        return array_values(array_unique([...$pinned, ...$exact, ...array_map('strval', array_keys($scores))]));
    }

    /**
     * The products among the hits that hold the whole query, by id.
     *
     * @param  list<array{id: string, full?: bool}>  $hits
     * @return array<string, true>
     */
    /**
     * Whether a product holds one of the values read from the words for one filter: it carries it, or its own words
     * say it ("זהב צהוב" in the title of a ring the scan has not marked) while it carries no other value of that filter
     * (or only a broader one, "זהב" for "זהב צהוב"). A pendant whose clasp is "סגר טבעת" is no ring. As the drawer reads it (filtered).
     *
     * @param  array<int, string>  $values  code => label
     * @param  list<array<string, mixed>>  $facets
     */
    private static function holdsRead(array $r, int $at, array $values, array $facets): bool
    {
        $carried = array_map('intval', (array) ($r['f'] ?? []));

        if (array_intersect(array_keys($values), $carried) !== []) {
            return true;
        }

        $words = fn (string $label): array => explode(' ', HebrewSearch::normalize($label));
        $text = (string) ($r['title'] ?? '').' '.(string) ($r['kw'] ?? '');

        return array_any($values, fn (string $label): bool => ! array_any($carried, fn (int $code): bool => intdiv($code, 1000) === $at
                && array_diff($words((string) ($facets[$at]['v'][$code % 1000]['l'] ?? '')), $words($label)) !== [])
            && HebrewSearch::says($text, $label));
    }

    private static function whole(array $hits): array
    {
        $whole = [];

        foreach ($hits as $hit) {
            if (($hit['full'] ?? false) && str_starts_with($hit['id'], 'p:')) {
                $whole[$hit['id']] = true;
            }
        }

        return $whole;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function present(array $record): array
    {
        [$prefix, $externalId] = explode(':', (string) $record['id'], 2);

        return array_filter([
            'id' => $record['id'],
            'type' => $record['t'],
            'external_id' => $externalId,
            'title' => $record['title'],
            'url' => $record['url'] ?? null,
            'image' => $record['img'] ?? null,
            'image2' => $record['i2'] ?? null,
            'kind' => $record['kind'] ?? null,
            'products' => $record['n'] ?? null,
            'buy' => isset($record['buy']) ? $record['buy'] === 1 : null,
            'sku' => $record['sku'] ?? null,
            'answer' => $record['ans'] ?? null,
            'sources' => $record['src'] ?? null,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * The spelling search of each side of an "או", their hits taken in turn, each product once.
     *
     * @return list<array<string, mixed>>
     */
    private static function either(array $engine, string $query): array
    {
        $sides = array_map(fn (string $side): array => HebrewSearch::search($engine, $side), HebrewSearch::either($query));
        if (count($sides) === 1) {
            return $sides[0];
        }
        $out = [];
        for ($i = 0; $i < max(array_map('count', $sides)); $i++) {
            foreach ($sides as $hits) {
                if (isset($hits[$i]) && ! isset($out[$hits[$i]['id']])) {
                    $out[$hits[$i]['id']] = $hits[$i];
                }
            }
        }

        return array_values($out);
    }
}
