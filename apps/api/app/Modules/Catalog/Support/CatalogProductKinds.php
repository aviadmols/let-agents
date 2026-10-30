<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Facades\Cache;

/**
 * Kinds from the store's own product types, categories and tags. How many active products carry
 * each is counted once per catalog change and kept, so a request reads no more than the products
 * it asks about.
 */
final class CatalogProductKinds implements ProductKinds
{
    /** A tag on more than this share of the products says nothing about kind ("כל המוצרים", the brand). */
    private const TAG_MAX_SHARE = 0.4;

    /** A title's first word on more than this share of the products is the store's, not a kind ("מארז" in a gift shop). */
    private const HEAD_MAX_SHARE = 0.6;

    /** A series may run through much of a small store ("אייקוני - כדורים פורחים" on half of it), never all of it. */
    private const SERIES_MAX_SHARE = 0.75;

    /**
     * A campaign, not a kind: a sale, a season, a date, a collection, what is new or in fashion ("sale 20%", "july20",
     * "חזרה לגן", "Top 10", "חדש באתר", "CLUB 20%", "כל מה שחם", "BACK TO SCHOOL", "טרנדים"), a season ("חורף 26") or a stock
     * code ("NOS").
     */
    private const CAMPAIGN = '/\d|%|\b(sale|off|new|top|last|chance|outlet|clearance|black friday|club|back to school|trend\w*|collection|nos|winter|summer|spring|autumn|fall|season\w*)\b|מבצע|הנחה|סייל|חדש|חזרה ל|כל מה ש|טרנד|קולקצי|חורף|קיץ|אביב|סתיו|עונה|עונת/iu';

    public function campaign(string $name): bool
    {
        return preg_match(self::CAMPAIGN, $name) === 1;
    }

    /** Words a store uses for a print, a collection or a series. */
    private const SERIES_WORDS = '/הדפס|דוגמ|קולקצי|סדרת|אייקוני|print|pattern|collection|series/iu';

    public function marks(CatalogProduct $product): array
    {
        $counts = $this->counts((string) $product->shop_id);
        $own = self::own($product, $counts['prefix']);

        // A type no other product has is the product's name, not its kind (Mycolivia types each formula by itself:
        // "Dream", "Joy", "Focus"; as kinds, no product had a like). Its kind is then read as if it had none.
        // A season or a campaign typed as the type is none (Chozen types its products "חורף 26": a slipper and a fleece
        // were one kind).
        if ($own['type'] !== '' && ($counts['type'][$own['type']] ?? 0) >= 2 && ! $this->campaign($own['type'])) {
            return ['type:'.$own['type']];
        }

        // A Hebrew title opens with what the product is ("תיק גב", "נעלי סניקרס", "חולצת טי"): a kind when a few share
        // it and it is not most of the store. Before the categories, which may be whom it is for ("גברים", "ילדים").
        $heads = $counts['head'] ?? [];
        if ($own['head'] !== '' && ($heads[$own['head']] ?? 0) >= 2 && ($heads[$own['head']] ?? 0) <= $counts['products'] * self::HEAD_MAX_SHARE) {
            return ['head:'.$own['head']];
        }

        // the categories that say what it is: never whom it is for ("גברים") or a campaign ("כל מה שחם", "SS26"), which a
        // backpack shares with skate shoes (Vans, 26.10)
        $categories = array_values(array_filter($own['categories'], fn (string $c): bool => ! $this->campaign($c) && ! $this->onlyWhom($c)));
        if ($categories !== []) {
            return array_map(fn (string $c): string => 'category:'.$c, $categories);
        }

        $limit = max(2, (int) floor($counts['products'] * self::TAG_MAX_SHARE));

        // the tags that say what it is, as the categories: never whom alone ("גברים/יוניסקס"), which a shirt shares with a
        // sneaker, nor its brand ("clarins")
        return array_values(array_map(fn (string $t): string => 'tag:'.$t, array_filter(
            $own['tags'],
            fn (string $t): bool => ($counts['tag'][$t] ?? 0) <= $limit && $t !== $own['title'] && preg_match(self::CAMPAIGN, $t) !== 1 && ! $this->onlyWhom($t)
                && $t !== mb_strtolower(trim((string) $product->brand)),
        )));
    }

    /** Whether a tag or category says what a product is: not a campaign, whom alone, its brand, or most of the store. */
    private function groupSaysWhat(string $name, CatalogProduct $product, int $count, int $products): bool
    {
        return ! $this->campaign($name) && ! $this->onlyWhom($name) && $name !== mb_strtolower(trim((string) $product->brand)) && $count <= $products * self::HEAD_MAX_SHARE;
    }

    /**
     * Whether a title opens with a brand's name spelled a letter apart ("בלנסטון" for "בלנדסטון": Chozen writes both), word
     * for word, each of four letters or more.
     */
    private static function spelledLike(string $title, string $prefix): bool
    {
        $name = explode(' ', $prefix);
        $words = array_slice(preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, count($name));
        if (count($words) < count($name) || count(preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: []) <= count($name)) {
            return false;
        }
        foreach ($name as $i => $word) {
            $theirs = (string) $words[$i];
            if ($theirs !== $word && (mb_strlen($word) < 4 || abs(mb_strlen($word) - mb_strlen($theirs)) > 1 || levenshtein($word, $theirs) > 2)) {
                return false;
            }
        }

        return true;
    }

    /** Whether these words of a title say what it is: a Hebrew word that is not whom it is for ("סניקרס", not "לגברים"). */
    private static function saysWhat(array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match("/^\p{Hebrew}(?:[\p{Hebrew}'׳]*\p{Hebrew})$/u", (string) $word) === 1 && self::saidIn((string) $word) === []) {
                return true;
            }
        }

        return false;
    }

    public function sameKind(CatalogProduct $product, array $externalIds, ?array &$near = null): array
    {
        $near = [];
        $marks = $this->marks($product);
        $counts = $this->counts((string) $product->shop_id);
        // no kind the store shares, but a word its title says it by ("בושם", "ג'ינס"): only what says the same, never every
        // product of its people (Chozen: a perfume's alike were boots and rings); what shares a tag or a category that says
        // what it is ("מכנסיים", never "גברים/יוניסקס" or one on most of the store) is near it, what may interest
        $nearBy = [];
        if ($marks === [] && ($head = self::own($product, $counts['prefix'])['head']) !== '') {
            $marks = ['head:'.$head];
            $own = self::own($product, $counts['prefix']);
            $nearBy = [
                ...array_map(fn (string $c): string => 'category:'.$c, array_filter($own['categories'], fn (string $c): bool => $this->groupSaysWhat($c, $product, (int) ($counts['category'][$c] ?? 0), $counts['products']))),
                ...array_map(fn (string $t): string => 'tag:'.$t, array_filter($own['tags'], fn (string $t): bool => $this->groupSaysWhat($t, $product, (int) ($counts['tag'][$t] ?? 0), $counts['products']))),
            ];
        }

        // a product of no known kind keeps them all, of its own people still (a man's, never a woman's)
        if ($externalIds === [] || ($marks === [] && self::audience($product) === null)) {
            return $externalIds;
        }

        $others = CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $externalIds)->with('categories:id,name')->get(['id', 'external_id', 'title', 'brand', 'payload'])
            ->keyBy(fn (CatalogProduct $p): string => (string) $p->external_id);

        // A thing that is read is of one kind with every other: a bookshop's categories (an imprint, a publisher, the
        // bestsellers, the newest) file its books, they do not say what each is; what a book is about is for the ranking
        // by tags and by meaning to tell (Sela Meir, 28.10: a bundle filed under its imprint had no alike at all).
        if ($this->isRead($product)) {
            return array_values(array_filter($externalIds, fn (string $id): bool => ($other = $others->get($id)) !== null && $this->isRead($other)));
        }
        $audience = self::audience($product);
        $prefixes = $counts['prefix'];

        // Two titles that name two kinds ("ג'ינס", "מכנסיים קצרים") are two, whatever tag the store gives both ("מכנסיים"):
        // near it, never like it (Chozen, 28.10: a pair of jeans' alike were trousers and shorts). A word is a kind only
        // where the store opens enough titles with it: a book's title opens with its own words ("מפגש", "חטוף"), which
        // name no kind (Sela Meir, 28.10: a hostage's memoir had three alike and the other hostages' books "maybe").
        $head = self::own($product, $prefixes)['head'];
        // two openings are two kinds where neither product is a thing that is read, and the store opens enough titles
        // with one of them ("מכנסיים"): a lone "ג'ינס" beside them is still another kind
        $named = fn (string $h): bool => $h !== '' && ($counts['head'][$h] ?? 0) >= self::SUB_KIND_MIN;
        $read = $this->isRead($product);
        $keep = function (array $marks, array $heads, bool $nearToo) use ($externalIds, $others, $audience, $prefixes, $nearBy, $product, $named, $read, &$near): array {
            $kept = [];
            foreach ($externalIds as $id) {
                $other = $others->get($id);
                if ($other === null || ! self::sameAudience($audience, self::audience($other))) {
                    continue;
                }
                if ($marks !== [] && array_intersect($marks, self::allMarks($other, $prefixes)) === []) {
                    if ($nearToo && $nearBy !== [] && array_intersect($nearBy, self::allMarks($other, $prefixes)) !== [] && (string) $id !== (string) $product->external_id) {
                        $near[] = $id;
                    }

                    continue;
                }
                $theirs = self::own($other, $prefixes)['head'];
                if ($heads !== [] && $theirs !== '' && ! in_array($theirs, $heads, true) && ! $read && ! $this->isRead($other)
                    && ($named($heads[0]) || $named($theirs)) && (string) $id !== (string) $product->external_id) {
                    if ($nearToo) {
                        $near[] = $id;
                    }

                    continue;
                }
                $kept[] = $id;
            }

            return $kept;
        };
        $kept = $keep($marks, $head !== '' ? [$head] : [], true);

        // Few of its own kind: a narrower kind its store's own tags name it by is its kind too: one whose products the store
        // files under its own kind as well (Chozen, 30.10: "פלדיום נעליים BAGGY" is tagged "מגפיים", and the boots are tagged
        // "נעליים": a shoe tagged a boot is a boot; read as "נעליים" alone, the boots its pictures found first were another
        // kind, and one sandal stood as its alike). Never a broader one: jeans tagged "מכנסיים" are not trousers, which no one
        // tags "ג'ינס" (28.10). A product with enough of its own kind keeps to it.
        $bases = fn (CatalogProduct $p): array => array_map(fn (string $t): string => self::base($t), self::own($p, $prefixes)['tags']);
        $tagHeads = array_values(array_filter(array_unique(array_map(
            fn (string $t): string => preg_match("/^\p{Hebrew}(?:[\p{Hebrew}'׳]*\p{Hebrew})$/u", $t) === 1 ? self::base($t) : '',
            self::own($product, $prefixes)['tags'],
        )), function (string $h) use ($head, $named, $others, $prefixes, $bases): bool {
            if ($h === '' || $head === '' || $h === $head || ! $named($h)) {
                return false;
            }
            $theirs = $others->filter(fn (CatalogProduct $o): bool => self::own($o, $prefixes)['head'] === $h);

            return $theirs->isNotEmpty() && $theirs->filter(fn (CatalogProduct $o): bool => in_array($head, $bases($o), true))->count() * 2 >= $theirs->count();
        }));
        if ($tagHeads !== [] && count(array_diff($kept, [(string) $product->external_id])) < self::SUB_KIND_MIN) {
            $wider = $keep([...$marks, ...array_map(fn (string $h): string => 'head:'.$h, $tagHeads)], $head !== '' ? [$head, ...$tagHeads] : $tagHeads, false);
            $near = array_values(array_diff($near, $wider));
            $kept = array_values(array_filter($externalIds, fn (string $id): bool => in_array($id, $kept, true) || in_array($id, $wider, true)));
        }

        // Of shoes, home slippers ("נעלי בית"), never running shoes ("נעלי ריצה") or flats ("נעלי בובה") (Chozen, 28.10):
        // where its title names it by two words and enough of the rest are named by them too, only those.
        $sub = self::own($product, $prefixes)['sub'];
        if ($sub !== '') {
            $named = array_values(array_filter($kept, fn (string $id): bool => $id !== (string) $product->external_id && self::own($others->get($id), $prefixes)['sub'] === $sub));
            if (count($named) >= self::SUB_KIND_MIN) {
                $near = [...array_values(array_filter($kept, fn (string $id): bool => $id !== (string) $product->external_id && ! in_array($id, $named, true))), ...$near];
                $kept = array_values(array_filter($kept, fn (string $id): bool => $id === (string) $product->external_id || in_array($id, $named, true)));
            }
        }

        return $kept;
    }

    /** What a thing that is read is called: by a title's first word, a type or a category. */
    private const READ = '/(^|[^\p{L}])(ספר|ספרי|ספרים|ספרימ|ספרון|ספרוני|ספרונים|רומן|רומנים|מגזין|מגזינים|חוברת|חוברות|books?|novels?|e-?books?|audiobooks?|magazines?)([^\p{L}]|$)/iu';

    public function isRead(CatalogProduct $product): bool
    {
        $first = (string) (preg_split('/\s+/u', trim((string) $product->title))[0] ?? '');
        $filed = implode(' ', [(string) ($product->payload['product_type'] ?? ''), ...array_map(fn ($c): string => (string) $c->name, $product->relationLoaded('categories') ? $product->categories->all() : $product->categories()->get(['id', 'name'])->all())]);

        return preg_match(self::READ, $first) === 1 || preg_match(self::READ, $filed) === 1;
    }

    /** A kind named by two words ("נעלי בית") is the alike's only where this many others are named by them. */
    private const SUB_KIND_MIN = 3;

    /** Words that say whom a product is for, as the store writes them in its titles, tags and categories. */
    private const AUDIENCE = [
        'women' => '/^(?:[לוה]{0,2}נשים|[לוה]{0,2}אישה|[לוה]{0,2}נשי|women|womens|woman|ladies|female)$/u',
        'men' => '/^(?:[לוה]{0,2}גברים|[לוה]{0,2}גבר|men|mens|man|male)$/u',
        'kids' => '/^(?:[לוה]{0,2}ילדים|[לוה]{0,2}ילדות|[לוה]{0,2}ילד|[לוה]{0,2}ילדה|[לוה]{0,2}נוער|[לוה]{0,2}בנים|[לוה]{0,2}בנות|kids|kid|boys|girls|youth|junior|juniors|children|child)$/u',
        'babies' => '/^(?:[לוה]{0,2}תינוקות|[לוה]{0,2}תינוק|[לוה]{0,2}תינוקת|[לוה]{0,2}פעוטות|[לוה]{0,2}פעוטים|baby|babies|infant|infants|toddler|toddlers|newborn)$/u',
        'unisex' => '/^(?:unisex|יוניסקס)$/u',
    ];

    /**
     * Whom a product is for, as the store says it in its title, else its product type, categories and tags ("לנשים",
     * "ביגוד_גברים", "_תינוקות", "kids"): women, men, kids, babies, or null when it says none or both men and women
     * (unisex). Never its description, which may say "מתאים לגברים ולנשים" of anything.
     */
    public static function audience(CatalogProduct $product): ?string
    {
        $said = self::saidAudiences($product);

        // the young first: "נעלי ספורט ילדים יוניסקס" is a child's, for boys and girls, and was a man's boot's alike (Chozen, 30.10)
        foreach (['babies', 'kids'] as $audience) {
            if (isset($said[$audience])) {
                return $audience;
            }
        }

        if (isset($said['unisex']) || (isset($said['women']) && isset($said['men']))) {
            return null;
        }

        foreach (['women', 'men'] as $audience) {
            if (isset($said[$audience])) {
                return $audience;
            }
        }

        return null;
    }

    public function audiences(CatalogProduct $product): array
    {
        $said = self::saidAudiences($product);
        // the young first: "סניקרס לילדים" in a men's category is a child's
        if (isset($said['babies']) || isset($said['kids'])) {
            return array_values(array_filter(['kids', 'babies'], fn (string $a): bool => isset($said[$a])));
        }
        if (isset($said['unisex'])) {
            return ['women', 'men'];
        }

        return array_values(array_filter(['women', 'men'], fn (string $a): bool => isset($said[$a])));
    }

    public function onlyWhom(string $name): bool
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $words !== [] && array_filter($words, fn (string $word): bool => array_filter(self::AUDIENCE, fn (string $p): bool => preg_match($p, $word) === 1) === [] && ! in_array($word, ['ו', 'and', 'for'], true)) === [];
    }

    /**
     * Every audience word its title says; when its title says none, every one its type, tags and categories say. The
     * title is the product's own word: "טישירט לגברים" tagged נשים is a man's (Chozen, 28.10: shown for "חולצה לנשים").
     *
     * @return array<string, true>
     */
    private static function saidAudiences(CatalogProduct $product): array
    {
        $own = self::saidIn((string) $product->title);

        return $own !== [] ? $own : self::saidIn(implode(' ', [(string) $product->title, (string) ($product->payload['product_type'] ?? ''),
            ...array_map('strval', (array) ($product->payload['tags'] ?? [])), ...array_map(fn (array $path): string => implode(' ', $path), $product->categoryPaths())]));
    }

    /** @return array<string, true> */
    private static function saidIn(string $text): array
    {
        $text = mb_strtolower($text);
        $said = [];
        foreach (preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach (self::AUDIENCE as $audience => $pattern) {
                if (preg_match($pattern, $word) === 1) {
                    $said[$audience] = true;
                }
            }
        }

        return $said;
    }

    /**
     * Whether two products are for the same people: a man's shirt is never like a woman's, a grown-up's shoe never like
     * a child's. Kids and babies are one (an infant's sneaker is like a toddler's); a product for anyone is like any.
     */
    private static function sameAudience(?string $a, ?string $b): bool
    {
        $young = ['kids' => true, 'babies' => true];

        return $a === null || $b === null || $a === $b || (isset($young[$a]) && isset($young[$b]));
    }

    public function hasLike(CatalogProduct $product): bool
    {
        $counts = $this->counts((string) $product->shop_id);

        foreach ($this->marks($product) as $mark) {
            [$kind, $value] = explode(':', $mark, 2);
            if (($counts[$kind][$value] ?? 0) >= 2) {
                return true;
            }
        }

        return false;
    }

    public function series(CatalogProduct $product): ?array
    {
        $counts = $this->counts((string) $product->shop_id);
        $own = self::own($product, $counts['prefix']);
        $limit = max(2, min($counts['products'] - 1, (int) floor($counts['products'] * self::SERIES_MAX_SHARE)));
        $best = null;

        foreach ($product->payload['tags'] ?? [] as $tag) {
            $tag = trim((string) $tag);
            $key = mb_strtolower($tag);
            $count = $counts['tag'][$key] ?? 0;
            // A name of its own ("אייקוני - כדורים פורחים"), not the bare word ("קולקציות").
            if ($count >= 2 && $count <= $limit && $key !== $own['title'] && str_contains($tag, ' ') && preg_match(self::SERIES_WORDS, $tag) === 1 && preg_match(self::CAMPAIGN, $tag) !== 1
                && ($best === null || $count > $best['count'] + 1)) {
                $best = ['tag' => $tag, 'count' => $count - 1];
            }
        }

        return $best;
    }

    /**
     * Every mark another product could match with: its type, categories and tags, whatever its
     * own kind is read from.
     *
     * @return list<string>
     */
    private static function allMarks(CatalogProduct $product, array $prefixes): array
    {
        $own = self::own($product, $prefixes);

        return [
            ...($own['type'] !== '' ? ['type:'.$own['type']] : []),
            ...($own['head'] !== '' ? ['head:'.$own['head']] : []),
            ...array_map(fn (string $c): string => 'category:'.$c, $own['categories']),
            ...array_map(fn (string $t): string => 'tag:'.$t, $own['tags']),
        ];
    }

    /** A Hebrew noun without its plural or construct ending: "חולצת", "חולצה", "חולצות" are "חולצ"; "נעלי", "נעליים" "נעל". */
    private static function base(string $word): string
    {
        // a doubled yod is a spelling of one ("נעליי" is "נעלי")
        $word = (string) preg_replace('/(?:יים|ים|ות)$/u', '', (string) preg_replace('/יי$/u', 'י', $word));

        return mb_strlen($word) > 3 ? (string) preg_replace('/[יהת]$/u', '', $word) : $word;
    }

    /**
     * @param  array<string, string>  $prefixes  the words each brand's titles open with (brandPrefixes)
     * @return array{type: string, head: string, sub: string, categories: list<string>, tags: list<string>, title: string}
     */
    private static function own(CatalogProduct $product, array $prefixes = []): array
    {
        $clean = fn (mixed $v): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $v)));
        // the title's first word, when it is Hebrew (an English title opens with its model, "Old Skool Backpack"), as
        // its base: a plural or a construct form is the same kind ("חולצת טי" and "חולצה", "נעלי" and "נעליים")
        // A title that opens with its brand ("נורתפייס נעלי בית", "אול סיינטס טישירט": Chozen) says what it is after it.
        $title = trim((string) $product->title);
        $prefix = $prefixes[mb_strtolower(trim((string) $product->brand))] ?? '';
        $words = preg_split('/[\s,\-–—|:]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($prefix !== '' && (str_starts_with($title.' ', $prefix.' ') || self::spelledLike($title, $prefix))) {
            $words = array_slice(preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [], count(explode(' ', $prefix)));
            $words = preg_split('/[\s,\-–—|:]+/u', implode(' ', $words), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            // after the brand, a model's name may come first ("אליזבת ארדן GREEN TEA דאודורנט"): what it is follows it
            while ($words !== [] && preg_match('/\p{Hebrew}/u', (string) $words[0]) !== 1) {
                array_shift($words);
            }
        }
        // a set, a box or a pair of something is that something ("סט שמלה", "מארז טבעות", "זוג מוצצים")
        if (isset($words[1]) && in_array((string) $words[0], self::CONTAINERS, true) && preg_match('/^\p{Hebrew}/u', (string) $words[1]) === 1) {
            array_shift($words);
            if (isset($words[1]) && $words[0] === 'של') {
                array_shift($words);
            }
        }
        $first = (string) ($words[0] ?? '');
        $second = (string) ($words[1] ?? '');
        // a geresh is a letter of a Hebrew word ("ג'קט")
        $head = preg_match("/^\p{Hebrew}(?:[\p{Hebrew}'׳]*\p{Hebrew})?$/u", $first) === 1 ? self::base($first) : '';

        return [
            'type' => $clean($product->payload['product_type'] ?? ''),
            'head' => $head,
            // a plural's construct form opens a kind of two words ("נעלי בית", "כפכפי סליידס"), never by whom or what colour
            'sub' => $head !== '' && preg_match('/י$/u', $first) === 1 && preg_match("/^\p{Hebrew}(?:[\p{Hebrew}'׳]*\p{Hebrew})$/u", $second) === 1
                && self::saidIn($second) === [] && ! ColorWords::knows($second) ? $head.' '.self::base($second) : '',
            'categories' => array_values(array_unique(array_filter(array_map(fn (array $path): string => $clean(end($path) ?: ''), $product->categoryPaths())))),
            'tags' => array_values(array_unique(array_filter(array_map($clean, (array) ($product->payload['tags'] ?? []))))),
            'title' => $clean($product->title),
        ];
    }

    /**
     * How many active products carry each type, category and tag, kept until the catalog changes.
     *
     * @return array{products: int, type: array<string, int>, head: array<string, int>, category: array<string, int>, tag: array<string, int>, prefix: array<string, string>}
     */
    private function counts(string $shopId): array
    {
        $active = CatalogProduct::query()->whereNull('removed_at');
        $version = '4|'.$active->count().'|'.$active->max('updated_at');

        return Cache::remember('catalog:kinds:'.$shopId.':'.md5($version), now()->addHours(12), function (): array {
            $prefixes = self::brandPrefixes();
            $counts = ['products' => 0, 'type' => [], 'head' => [], 'category' => [], 'tag' => [], 'prefix' => $prefixes];

            CatalogProduct::query()->whereNull('removed_at')->select(['id', 'title', 'brand', 'payload'])->orderBy('id')
                ->chunk(500, function ($products) use (&$counts, $prefixes): void {
                    foreach ($products as $product) {
                        $own = self::own($product, $prefixes);
                        $counts['products']++;
                        if ($own['type'] !== '') {
                            $counts['type'][$own['type']] = ($counts['type'][$own['type']] ?? 0) + 1;
                        }
                        if ($own['head'] !== '') {
                            $counts['head'][$own['head']] = ($counts['head'][$own['head']] ?? 0) + 1;
                        }
                        foreach ($own['categories'] as $category) {
                            $counts['category'][$category] = ($counts['category'][$category] ?? 0) + 1;
                        }
                        foreach ($own['tags'] as $tag) {
                            $counts['tag'][$tag] = ($counts['tag'][$tag] ?? 0) + 1;
                        }
                    }
                });

            return $counts;
        });
    }

    /** Words that hold a kind rather than name one: a set of necklaces is necklaces. */
    private const CONTAINERS = ['סט', 'סטים', 'מארז', 'מארזי', 'זוג', 'זוגות', 'ערכה', 'ערכת', 'חבילה', 'חבילת'];

    /** A brand's titles open with its name of one word when this share of them do… */
    private const PREFIX_SHARE = 0.6;

    /** …and a name of two words or three when this share of them do. */
    private const PREFIX_SHARE_WORDS = 0.8;

    /**
     * The words each brand's titles open with, where the store writes its brand first ("נורתפייס", "אול סיינטס",
     * "טואוס": Chozen): of a store of two brands or more, a brand of three products or more (or fewer, where most brands
     * open their titles with their name), the longest opening of up to three words that most of its titles have and no
     * other brand's title opens with, up to a word other brands'
     * titles say too ("סניקרס", "כפכפי"), which is what the products are, never a brand's name. A store of one brand has none.
     *
     * @return array<string, string> lower-case brand => the words, as its titles write them
     */
    private static function brandPrefixes(): array
    {
        $titles = [];
        CatalogProduct::query()->whereNull('removed_at')->whereNotNull('brand')->select(['id', 'title', 'brand'])->orderBy('id')
            ->chunk(1000, function ($products) use (&$titles): void {
                foreach ($products as $product) {
                    $brand = mb_strtolower(trim((string) $product->brand));
                    if ($brand !== '') {
                        $titles[$brand][] = preg_split('/\s+/u', trim((string) $product->title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    }
                }
            });
        if (count($titles) < 2) {
            return [];
        }

        $opens = fn (array $words, array $prefix): bool => count($words) > count($prefix) && array_slice($words, 0, count($prefix)) === $prefix;
        // the words each brand's titles say, and so those other brands' titles say
        $said = [];
        foreach ($titles as $brand => $list) {
            foreach ($list as $words) {
                foreach ($words as $word) {
                    $said[$word][$brand] = true;
                }
            }
        }
        // how many other brands' titles say each word
        $elsewhere = [];
        foreach ($said as $word => $brands) {
            foreach (array_keys($titles) as $brand) {
                $others = count($brands) - (isset($brands[$brand]) ? 1 : 0);
                if ($others > 0) {
                    $elsewhere[$brand][$word] = $others;
                }
            }
        }
        $find = function (string $brand, array $list) use ($titles, $elsewhere, $opens): ?string {
            for ($k = 3; $k >= 1; $k--) {
                $seen = [];
                foreach ($list as $words) {
                    if (count($words) > $k) {
                        $key = implode(' ', array_slice($words, 0, $k));
                        $seen[$key] = ($seen[$key] ?? 0) + 1;
                    }
                }
                if ($seen === []) {
                    continue;
                }
                arsort($seen);
                $key = (string) array_key_first($seen);
                // a name of one word may be spelled two ways ("בלנדסטון", "בלנסטון"); a longer one is most of its titles'
                if ($seen[$key] < count($list) * ($k === 1 ? self::PREFIX_SHARE : self::PREFIX_SHARE_WORDS)) {
                    continue;
                }
                // its name ends before a word other brands' titles say too: "בירקנשטוק כפכפי סליידס" is בירקנשטוק and what
                // all its products are ("וג'ה סניקרס", "פלדיום מגפיים"), and what they are is the kind
                // (another brand's title that says its whole name is a gift of it, "מארז אליזבת ארדן", and its first word may
                // come up once besides; a kind is said by many)
                $prefix = [];
                foreach (explode(' ', $key) as $at => $word) {
                    $phrase = ' '.implode(' ', [...$prefix, $word]).' ';
                    $saying = $at === 0 ? ($elsewhere[$brand][$word] ?? 0) : 0;
                    foreach ($at === 0 ? [] : $titles as $other => $theirs) {
                        if ($other !== $brand && isset($elsewhere[$brand][$word]) && array_filter($theirs, fn (array $w): bool => in_array($word, $w, true) && ! str_contains(' '.implode(' ', $w).' ', $phrase)) !== []) {
                            $saying++;
                        }
                    }
                    if ($saying >= ($at === 0 ? 2 : 1)) {
                        break;
                    }
                    $prefix[] = $word;
                }
                // and before what most of its titles say what they are by: where no other brand says "סניקרס", "וג'ה
                // סניקרס V-90 לגברים" is וג'ה, not "וג'ה סניקרס" leaving a model and whom (Chozen's demo, 28.10: a shirt
                // was a sneaker's alike, by the tag they share, "גברים/יוניסקס")
                while ($prefix !== [] && count(array_filter($list, fn (array $words): bool => self::saysWhat(array_slice($words, count($prefix))))) < count($list) / 2) {
                    array_pop($prefix);
                }
                if ($prefix === []) {
                    return null;
                }
                $key = implode(' ', $prefix);
                foreach ($titles as $other => $theirs) {
                    // another brand that mostly opens with it too: a name of what they both are, not of either
                    if ($other !== $brand && count(array_filter($theirs, fn (array $w): bool => $opens($w, $prefix))) * 2 > count($theirs)) {
                        continue 2;
                    }
                }

                return $key;
            }

            return null;
        };

        $out = [];
        $sure = 0;
        foreach ($titles as $brand => $list) {
            if (count($list) >= 3) {
                $sure++;
                if (($key = $find((string) $brand, $list)) !== null) {
                    $out[(string) $brand] = $key;
                }
            }
        }
        // A store that writes most of its brands first writes them all first: a brand of one or two products too (Teva's
        // one pair, "טבע סנדלי פלטפורמה", was a ring's alike, "טבע" being "טבעת" without its ending)
        if ($sure >= 2 && count($out) >= $sure / 2) {
            foreach ($titles as $brand => $list) {
                if (count($list) < 3 && ($key = $find((string) $brand, $list)) !== null) {
                    $out[(string) $brand] = $key;
                }
            }
        }
        ksort($out);

        return $out;
    }
}
