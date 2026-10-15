<?php

namespace App\Modules\Search\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\PictureContent;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Models\SearchResolution;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\PhotoLook;
use App\Modules\Search\Support\PhotoPlacer;

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
    /** The most product ids a search returns besides the shown ones: what the filters and the next pages work on. */
    private const ALL_IDS = 600;

    public const GROUPS = ['answer', 'product', 'content', 'category'];

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

    /** Products a tag of words brings, before the picture orders them. */
    private const PHOTO_PER_TAG = 200;

    /** How far down a product goes that has what the photographed object plainly lacks. */
    private const LACKS_PENALTY = 0.5;

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

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SemanticSearch $semantic,
        private readonly CountSearch $counter,
        private readonly ReadPhotoTags $photoTags,
        private readonly KeepPhotoSearch $keeper,
        private readonly RankPhotoResults $ranker,
    ) {}

    /**
     * @param  list<string>|null  $only  groups to return; null for all
     * @return array{query: string, total: int, semantic: bool, single: array{id: string, by: string}|null, focus: array{options: list<array{query: string, count: int}>, category: array<string, mixed>|null}|null, groups: array<string, list<array<string, mixed>>>, counts: array<string, int>}
     */
    /**
     * @param  bool  $meaning  false while a shopper is still typing: spelling only, code only, no vector
     */
    public function handle(string $shopId, string $raw, ?array $only = null, bool $count = true, ?int $perGroup = null, bool $meaning = true, array $visit = []): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $raw, $only, $count, $perGroup, $meaning, $visit): array {
            // "עד 2500 ש״ח": the amount is not searched for; products outside the range are kept away.
            [$priced, $minPrice, $maxPrice] = HebrewSearch::priceRange(mb_substr($raw, 0, 120));
            // "בלי יהלומים": that word is not searched for, and what holds it is kept away.
            [$query, $without] = HebrewSearch::without($priced);
            $index = trim($query) === '' ? null : LoadedIndex::for($shopId);
            // "הכי זול", "במבצע", "גדול מ-2": actions, not words. The same phrases in every shop, less
            // those its index leaves out (no size: "גדול" stays a word).
            [$query, $actions] = HebrewSearch::actions($query, $index['actions'] ?? []);
            // "שטיח כחול 80 על 150": a colour or a size the shop filters by is a filter, not a word (the page shows it as one).
            $query = $index === null ? $query : HebrewSearch::withoutFacetWords($index, $query);
            $empty = ['query' => $query, 'total' => 0, 'semantic' => false, 'groups' => array_fill_keys(self::GROUPS, []), 'counts' => array_fill_keys(self::GROUPS, 0)];

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
            // No product holds every word ("מקדחה בצבע שחור"): the narrower searches that do find, to choose from.
            $focus = $minPrice === null && $maxPrice === null && $without === [] && $actions['ids'] === [] && ! $asks ? HebrewSearch::focus($index['engine'], $query) : [];

            // A sentence ("מה הכי טוב למברגה ביתית וזולה") holds words no product has: search
            // what it is about instead, so a question finds the products it asks about.
            if ($spelling === [] && str_contains($query, ' ') && $asks) {
                $about = HebrewSearch::aboutWords($index['engine'], $query);
                $spelling = $about !== '' && $about !== $query ? HebrewSearch::search($index['engine'], $about) : [];
            }
            // A guide or a page is a result only when it holds every word: one that shares a word of
            // "מברגה שחורה" is not about black screwdrivers. Products keep their wider net.
            $spelling = array_values(array_filter($spelling, fn (array $hit): bool => ! str_starts_with($hit['id'], 'c:') || $hit['full']));
            $byMeaning = $meaning;
            $meaning = $byMeaning ? $this->meaning($shopId, $query, $index['records']) : [];
            $pictures = $byMeaning ? $this->pictures($shopId, $query, $index['records']) : [];

            // "טבעת יהלום סוליטר בכסף": when products hold every word, only they are shown, however
            // close by meaning or by picture a gold solitaire or a silver zircon ring may be. The
            // search widens to products holding most of the words only when none holds them all.
            if (str_contains($query, ' ') && ($whole = self::whole($spelling)) !== []) {
                $spelling = array_values(array_filter($spelling, fn (array $hit): bool => isset($whole[$hit['id']]) || ! str_starts_with($hit['id'], 'p:')));
                $keep = fn (string $id): bool => isset($whole[$id]) || ! str_starts_with($id, 'p:');
                $meaning = array_values(array_filter($meaning, $keep));
                $pictures = array_values(array_filter($pictures, $keep));
            }
            // "עגילים עד 500": with a price, what the words name is decided before the price, never
            // after it. Only products that hold the words: by meaning a pendant is close to
            // earrings. And when some hold them as typed (in the title, a category, an attribute or
            // a synonym), only those: "צמיד" is close to "צמודים" by spelling, and when no bracelet
            // is that cheap the stud earrings would be all that is left.
            $products = array_values(array_filter($spelling, fn (array $hit): bool => str_starts_with($hit['id'], 'p:')));
            if (($minPrice !== null || $maxPrice !== null) && $products !== []) {
                $exact = array_values(array_filter($products, fn (array $hit): bool => $hit['exact']));
                $held = array_flip(array_column($exact !== [] ? $exact : $products, 'id'));
                $keep = fn (string $id): bool => isset($held[$id]) || ! str_starts_with($id, 'p:');
                $spelling = array_values(array_filter($spelling, fn (array $hit): bool => $keep($hit['id'])));
                $meaning = array_values(array_filter($meaning, $keep));
                $pictures = array_values(array_filter($pictures, $keep));
            }
            $pinned = $this->resolved($query, $index['records']);
            // A question about a kind of product ("איזה עץ…") shows that kind first: products of the
            // categories named after it, before products that only mention the word (a bracket
            // "לעמודי עץ"). The assistant then picks from them too.
            $subject = str_contains($query, ' ') && $asks
                ? $this->subjectProducts($index, $query, $spelling)
                : [];
            $ranked = $this->merge($spelling, [$meaning, $pictures], [...$pinned, ...$subject]);
            $perGroup ??= (int) Settings::get('search.results_per_group', $shopId);

            $groups = array_fill_keys(self::GROUPS, []);

            foreach ($ranked as $id) {
                $record = $index['records'][$id] ?? null;
                $type = $record['t'] ?? null;

                if ($record !== null && $without !== [] && $type === 'product' && HebrewSearch::holdsAny($record['title'].' '.($record['kw'] ?? ''), $without)) {
                    continue;
                }

                if ($record !== null && $type === 'product' && ! HebrewSearch::inRange($record['pr'] ?? null, $minPrice, $maxPrice)) {
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

            // "הכי זול", "במבצע": filtered and sorted as asked, before any is cut.
            $groups['product'] = HebrewSearch::applyActions($groups['product'], $actions);

            // The one product first, wherever the merge put it, and also when only its code found it.
            if ($single !== null) {
                $groups['product'] = [
                    $index['records'][$single['id']],
                    ...array_values(array_filter($groups['product'], fn (array $r): bool => $r['id'] !== $single['id'])),
                ];
            }

            // Every product found, in order, for the quick filters and the pages beyond the first.
            $all = $only === null || in_array('product', $only, true) ? array_slice(array_column($groups['product'], 'id'), 0, self::ALL_IDS) : [];
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

            return ['query' => $query, 'total' => $total, 'semantic' => $meaning !== [] || $pictures !== [], 'resolved' => $pinned !== [], 'subject' => $subject !== [], 'actions' => $actions['ids'], 'ids' => $all, 'single' => $single, 'focus' => $focus, 'groups' => $groups, 'counts' => $counts];
        });
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

        $members = CatalogProduct::query()->active()
            ->whereHas('categories', fn ($q) => $q->whereIn('external_id', $categories))
            ->orderByDesc('in_stock')->orderBy('title')
            ->limit(200)->pluck('external_id')->map(fn ($id): string => 'p:'.$id)->all();
        $members = array_values(array_filter($members, fn (string $id): bool => isset($index['records'][$id])));
        $found = array_values(array_intersect(array_column($spelling, 'id'), $members));

        return array_slice(array_values(array_unique([...$found, ...$members])), 0, self::SUBJECT_PRODUCTS);
    }

    /**
     * Products like one in the results: by its picture when the shop's pictures are scanned, by
     * the meaning of its name and description otherwise. Both read vectors already stored, so no
     * model is asked; what was in stock last night comes first, and the product itself never.
     *
     * @return list<array<string, mixed>>
     */
    public function similar(string $shopId, string $externalId, int $limit): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $externalId, $limit): array {
            $index = LoadedIndex::for($shopId);
            $product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->first(['id', 'shop_id', 'title', 'payload']);

            if ($index === null || $product === null || $limit < 1) {
                return [];
            }

            // More than shown are read: those of another kind are left out.
            $ids = array_column($this->semantic->picturesReady() ? $this->semantic->lookAlike($product->id, $limit * 4) : [], 'external_id');

            if ($ids === []) {
                $ids = array_column($this->semantic->similarTo('product', $product->id, ['product'], $limit * 4), 'external_id');
            }

            // Of its own kind, or none: a baby cream's nearest pictures may be blankets on the same white background.
            $ids = app(ProductKinds::class)->sameKind($product, array_map('strval', $ids));

            $records = [];

            foreach ($ids as $id) {
                $record = $index['records']['p:'.$id] ?? null;

                if ($record !== null && (string) $id !== $externalId) {
                    $records[] = $record;
                }
            }

            usort($records, fn (array $a, array $b): int => ($b['s'] ?? 0) <=> ($a['s'] ?? 0));

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
                $products = $this->samePictureFirst($index, $hits, $this->taggedProducts($index, $tags, $hits, $shopId, $content, array_values(array_filter([$look->object, ...$look->details])), $look->lacks, $look->object));
                $products = $rank ? $this->ranker->handle($shopId, $bytes, $look->box, $products, self::described($look)) : $products;
                $record && $this->counter->photo($shopId, count($products));
                $record && $this->keeper->handle($shopId, $bytes, $look, $products, count($products), $cut, $visit);

                return ['total' => count($products), 'groups' => ['product' => $products], 'tags' => array_map(fn (array $t): array => array_diff_key($t, ['id' => 0, 'main' => 0]), $tags), 'searched' => true] + $seen;
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

            return ['total' => count($products), 'groups' => ['product' => $products], 'tags' => [], 'searched' => $hits !== [] || $index !== null] + $seen;
        });
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
        $candidates = array_map(fn ($id): array => ['external_id' => (string) $id, 'similarity' => (float) ($similarity[$id] ?? 0.0) + $wordsWeight * ($named[$id] ?? 0.0) - (isset($lacking[$id]) ? self::LACKS_PENALTY : 0.0)], array_keys($members));

        if ((bool) Settings::get('search.photo_in_stock_only', $shopId)) {
            $stocked = array_values(array_filter($candidates, fn (array $c): bool => (bool) $rows->get($c['external_id'])?->in_stock));
            $candidates = $stocked !== [] ? $stocked : $candidates;
        }

        // What the shopper photographed first (the main tag's products), then by likeness, then by
        // how many of the photo's tags a product shares. Likeness alone would put a scanned tool box
        // above a drill whose picture is not scanned yet.
        $main = array_keys(array_filter($tags, fn (array $t): bool => ($t['main'] ?? false) === true));
        $isMain = fn (array $c): int => isset($objectNamed[$c['external_id']]) || array_intersect($members[$c['external_id']], $main) !== [] ? 1 : 0;
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
     * Record ids found by meaning, nearest first.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return list<string>
     */
    private function meaning(string $shopId, string $query, array $records): array
    {
        if (! Features::enabled('search.semantic', $shopId) || mb_strlen($query) < (int) Settings::get('search.semantic_min_chars')) {
            return [];
        }

        $limit = (int) Settings::get('search.semantic_results');
        $floor = (float) Settings::get('search.semantic_min_similarity');
        // A guide or a page by meaning only when it is plainly about the words: a page about carts
        // is a little like anything a hardware store sells.
        // Only where the shop wants guides by meaning at all; otherwise a guide must hold the words.
        $contentFloor = Features::enabled('search.content_by_meaning', $shopId) ? max($floor, (float) Settings::get('search.content_min_similarity')) : INF;
        $ids = [];

        foreach ($this->semantic->nearText($shopId, $query, ['product', 'content'], $limit) as $hit) {
            $id = ($hit['source'] === 'product' ? 'p:' : 'c:').$hit['external_id'];

            if ($hit['similarity'] >= ($hit['source'] === 'product' ? $floor : $contentFloor) && isset($records[$id])) {
                $ids[] = $id;
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
        if (! Features::enabled('search.pictures', $shopId) || mb_strlen($query) < (int) Settings::get('search.semantic_min_chars')) {
            return [];
        }

        $floor = (float) Settings::get('search.picture_min_similarity');
        $ids = [];

        foreach ($this->semantic->picturesNearText($shopId, $query, (int) Settings::get('search.semantic_results')) as $hit) {
            $id = 'p:'.$hit['external_id'];

            if ($hit['similarity'] >= $floor && isset($records[$id])) {
                $ids[] = $id;
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
     * @param  list<list<string>>  $others  ranked ids by meaning, by picture
     * @param  list<string>  $pinned  what the night resolved for this query, first of all
     * @return list<string>
     */
    private function merge(array $spelling, array $others, array $pinned = []): array
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

        foreach ($others as $list) {
            foreach ($list as $rank => $id) {
                if (! in_array($id, $exact, true)) {
                    $scores[$id] = ($scores[$id] ?? 0) + 1 / (self::RRF_K + $rank);
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
