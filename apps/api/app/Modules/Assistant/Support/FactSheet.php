<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;

/**
 * What the writer may say, as facts with refs, built in code; and the gate its reply must pass.
 *
 * The writer of answer_site.v5 never reads a description or a page. It gets, for each product the
 * search found, the facts code holds about it: approved facts (type, spec, choice, flag, use, tag,
 * highlight...), the product's own fields (its name, category, the store's tags and attributes,
 * its SKU, the options in stock), and what code measured (in stock, on sale, its price rank). For
 * the store it gets the store's own statements, one line each. Every fact has a ref ("p3.f2",
 * "p3.stock", "k4") and an owner (the product, or the store).
 *
 * The reply is sentences, each saying what it is about and which facts it rests on. screen()
 * holds it to that: a ref that does not exist, or that belongs to something the sentence is not
 * about, refuses the answer (that is how a fact is kept on its own product); a number that none
 * of the sentence's facts holds refuses it too. The words themselves are the writer's: no list of
 * allowed words, so a natural sentence that says what its facts say stands.
 *
 * Nothing here reads free text: no description, no passage of a page. A product with no facts
 * is offered by its name and fields only; a question the facts do not answer is not answered.
 */
final class FactSheet
{
    /** Facts kept per product, in the order the question's type wants them. */
    private const PER_PRODUCT = 12;

    /** Store lines kept, and the shortest line that says anything. */
    private const STORE_LINES = 30;

    private const MIN_LINE = 6;

    /** A sentence with no ref is only a link between others: short, no number, about nothing. */
    private const LINK_WORDS = 10;

    /** Which approved facts come first, by the question's type (AnswerContext::type()). */
    private const ORDER = [
        'size' => [FactKind::Spec, FactKind::Choice, FactKind::Type, FactKind::Flag, FactKind::Brand, FactKind::Highlight, FactKind::Use, FactKind::Tag, FactKind::Promise],
        'attribute' => [FactKind::Choice, FactKind::Flag, FactKind::Tag, FactKind::Type, FactKind::Brand, FactKind::Spec, FactKind::Highlight, FactKind::Use, FactKind::Promise],
        'recommendation' => [FactKind::Type, FactKind::Use, FactKind::Highlight, FactKind::Choice, FactKind::Flag, FactKind::Spec, FactKind::Brand, FactKind::Tag, FactKind::Promise],
        'comparison' => [FactKind::Type, FactKind::Spec, FactKind::Choice, FactKind::Flag, FactKind::Highlight, FactKind::Brand, FactKind::Use, FactKind::Tag, FactKind::Promise],
        'default' => [FactKind::Type, FactKind::Brand, FactKind::Choice, FactKind::Flag, FactKind::Spec, FactKind::Use, FactKind::Highlight, FactKind::Tag, FactKind::Promise],
    ];

    /** The reply answer_site.v5 asks for. */
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'found' => ['type' => 'boolean'],
            'sentences' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string'],
                    'about' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['text', 'about', 'refs'],
                'additionalProperties' => false,
            ]],
            'picks' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'ref' => ['type' => 'string'],
                    'why' => ['type' => 'string'],
                    'refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['ref', 'why', 'refs'],
                'additionalProperties' => false,
            ]],
        ],
        'required' => ['found', 'sentences', 'picks'],
        'additionalProperties' => false,
    ];

    /**
     * The facts for one question, from what prepare() found: its products (by ref) and its
     * passages, of which only the store's own knowledge is read, line by line.
     *
     * @param  array{type: string, productsByRef: array<string, array<string, mixed>>, byRef: array<string, array<string, mixed>>, analysis: array<string, mixed>}  $prepared
     * @return array{asks: string|null, products: list<array<string, mixed>>, store: list<array{ref: string, say: string}>}
     */
    public static function build(array $prepared, string $question, string $locale): array
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $ranks = array_flip((array) ($prepared['analysis']['in_stock_cheapest_first'] ?? []));
            $catalog = CatalogProduct::query()->whereIn('external_id', array_column($prepared['productsByRef'], 'external_id'))->get()->keyBy('external_id');
            $facts = EnrichmentFact::query()->with('vocabulary')
                ->whereIn('product_id', $catalog->pluck('id'))->where('status', FactStatus::Approved)
                ->orderBy('id')->get()->groupBy('product_id');
            $order = self::ORDER[$prepared['type']] ?? self::ORDER['default'];
            $products = [];

            foreach ($prepared['productsByRef'] as $ref => $product) {
                $row = $catalog->get($product['external_id']);
                $said = [];

                foreach (collect($facts->get($row?->id, []))->sortBy(fn (EnrichmentFact $f): int => ($at = array_search($f->kind, $order, true)) === false ? 99 : $at)->values() as $fact) {
                    $text = self::factText($fact);
                    if ($text !== '' && ! in_array($text, $said, true)) {
                        $said[] = $text;
                    }
                }
                $said = array_slice($said, 0, self::PER_PRODUCT);

                // The product's own fields: structured, the store's, never its description.
                foreach (self::fields($product, $row) as $text) {
                    if (! in_array($text, $said, true)) {
                        $said[] = $text;
                    }
                }

                $sheetFacts = [];
                foreach ($said as $i => $text) {
                    $sheetFacts[] = ['ref' => $ref.'.f'.($i + 1), 'say' => $text];
                }
                // What code measured, each a fact of its own so a claim of it can be held to it.
                $sheetFacts[] = ['ref' => $ref.'.stock', 'say' => __('assistant::facts.'.($product['in_stock'] ? 'in_stock' : 'out_of_stock'))];
                if ($product['on_sale']) {
                    $sheetFacts[] = ['ref' => $ref.'.sale', 'say' => __('assistant::facts.on_sale')];
                }
                if (isset($ranks[$ref])) {
                    $sheetFacts[] = ['ref' => $ref.'.rank', 'say' => $ranks[$ref] === 0
                        ? __('assistant::facts.cheapest')
                        : __('assistant::facts.rank', ['rank' => $ranks[$ref] + 1, 'of' => count($ranks)])];
                }

                $products[] = ['ref' => $ref, 'name' => (string) $product['title'], 'facts' => $sheetFacts];
            }

            return ['asks' => $prepared['analysis']['asks'] ?? null, 'products' => $products, 'store' => self::store($prepared['byRef'])];
        } finally {
            app()->setLocale($previous);
        }
    }

    /** The writer's input: the question and the facts, nothing else. */
    public static function input(string $question, array $sheet): string
    {
        return (string) json_encode(['question' => $question] + $sheet, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Code's gate on a v5 reply. Hard on refs: every sentence and every pick's why rests on refs
     * that exist and belong to what it is about; a number must be in one of its facts. Not on
     * words: how a sentence says its facts is the writer's.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $productsByRef
     * @return array{answer: string, cited: list<string>, picks: list<array{ref: string, external_id: string, title: string, why: string}>, refusal: string|null, sentences: int}
     */
    public static function screen(array $data, array $sheet, array $productsByRef, string $question): array
    {
        [$facts, $owner] = self::index($sheet);
        $names = array_column($sheet['products'], 'name', 'ref');
        $refusal = null;
        $kept = [];
        $cited = [];
        $sentences = array_values(array_filter((array) ($data['sentences'] ?? []), 'is_array'));

        foreach ($sentences as $sentence) {
            $text = AnswerSiteQuestion::withoutRefs(trim((string) ($sentence['text'] ?? '')));
            $about = array_values(array_unique(array_map('strval', (array) ($sentence['about'] ?? []))));
            $refs = array_values(array_unique(array_map('strval', (array) ($sentence['refs'] ?? []))));

            if ($text === '') {
                continue;
            }

            $refusal = self::sentenceRefusal($text, $about, $refs, $facts, $owner, $names, $question);
            if ($refusal !== null) {
                break;
            }

            $kept[] = $text;
            array_push($cited, ...$refs);
        }

        $answer = $refusal === null ? trim(implode(' ', $kept)) : '';
        $refusal ??= $answer === '' ? 'no_answer' : null;

        $picks = [];
        $seen = [];
        foreach (array_filter((array) ($data['picks'] ?? []), 'is_array') as $pick) {
            $ref = (string) ($pick['ref'] ?? '');
            $why = AnswerSiteQuestion::withoutRefs(mb_substr(trim((string) ($pick['why'] ?? '')), 0, 160));
            $refs = array_values(array_unique(array_map('strval', (array) ($pick['refs'] ?? []))));

            if (! isset($productsByRef[$ref], $names[$ref]) || isset($seen[$ref]) || $why === ''
                || self::sentenceRefusal($why, [$ref], $refs, $facts, $owner, $names, $question, true) !== null) {
                continue;
            }

            $seen[$ref] = true;
            $picks[] = ['ref' => $ref, 'external_id' => (string) $productsByRef[$ref]['external_id'], 'title' => (string) $productsByRef[$ref]['title'], 'why' => $why];
            if (count($picks) >= 3) {
                break;
            }
        }

        return ['answer' => $answer, 'cited' => array_values(array_unique($cited)), 'picks' => $picks, 'refusal' => $answer === '' && $picks !== [] && $refusal === 'no_answer' ? null : $refusal, 'sentences' => count($kept)];
    }

    /**
     * The reply as one answer and its picks, the way a v4 reply looks: for the checker, the judge
     * and code's measures.
     *
     * @param  array<string, mixed>  $data
     * @return array{found: bool|null, answer: string, refs: list<string>, picks: list<array{ref: string, why: string}>}
     */
    public static function flat(array $data): array
    {
        $sentences = array_values(array_filter((array) ($data['sentences'] ?? []), 'is_array'));

        return [
            'found' => is_bool($data['found'] ?? null) ? $data['found'] : null,
            'answer' => trim(implode(' ', array_map(fn (array $s): string => trim((string) ($s['text'] ?? '')), $sentences))),
            'refs' => array_values(array_unique(array_merge(...array_map(fn (array $s): array => array_map('strval', (array) ($s['refs'] ?? [])), $sentences ?: [[]])))),
            'picks' => array_values(array_map(fn (array $p): array => ['ref' => (string) ($p['ref'] ?? ''), 'why' => (string) ($p['why'] ?? '')], array_filter((array) ($data['picks'] ?? []), 'is_array'))),
        ];
    }

    /**
     * What the checker reads of a v5 reply: the facts it cites, as the passages it rests on, and
     * each pick's facts as its description.
     *
     * @param  list<string>  $cited
     * @param  array<string, array<string, mixed>>  $productsByRef
     * @return array{byRef: array<string, array{title: string, text: string}>, productsByRef: array<string, array<string, mixed>>}
     */
    public static function forChecker(array $sheet, array $cited, array $productsByRef): array
    {
        [$facts, $owner] = self::index($sheet);
        $names = array_column($sheet['products'], 'name', 'ref');
        $byRef = [];
        foreach ($cited as $ref) {
            if (isset($facts[$ref])) {
                $byRef[$ref] = ['title' => $owner[$ref] === 'store' ? (string) __('assistant::facts.store') : (string) ($names[$owner[$ref]] ?? ''), 'text' => $facts[$ref]];
            }
        }

        $described = $productsByRef;
        foreach ($sheet['products'] as $product) {
            if (isset($described[$product['ref']])) {
                $described[$product['ref']]['about'] = implode("\n", array_column($product['facts'], 'say'));
            }
        }

        return ['byRef' => $byRef, 'productsByRef' => $described];
    }

    // ------------------------------------------------------------------ the rules

    /**
     * Why a sentence (or a pick's why) cannot be shown, or null. A link between sentences ("יש
     * כמה אפשרויות:") may cite nothing when it is short, has no number and is about nothing.
     *
     * @param  list<string>  $about
     * @param  list<string>  $refs
     */
    private static function sentenceRefusal(string $text, array $about, array $refs, array $facts, array $owner, array $names, string $question, bool $isPick = false): ?string
    {
        if (AnswerSiteQuestion::hasRef($text)) {
            return 'internal_ref';
        }
        if (preg_match('~₪|ש"ח|ש״ח|\d+\s*שקל~u', $text) === 1) {
            return 'price';
        }

        if ($refs === []) {
            $link = ! $isPick && $about === [] && preg_match('/\d/u', $text) !== 1 && count(preg_split('/\s+/u', $text) ?: []) <= self::LINK_WORDS;

            return $link ? null : 'no_refs';
        }

        foreach ($about as $subject) {
            if ($subject !== 'store' && ! isset($names[$subject])) {
                return 'unknown_subject';
            }
        }
        foreach ($refs as $ref) {
            if (! isset($facts[$ref])) {
                return 'unknown_ref';
            }
            // The fact belongs to what the sentence is about, or it is the store's own: one product's fact is never said of another.
            if ($owner[$ref] !== 'store' && ! in_array($owner[$ref], $about, true)) {
                return 'wrong_owner';
            }
        }

        // A number must be in the facts the sentence cites, in the names of what it is about, or in the question.
        $known = implode("\n", array_map(fn (string $ref): string => $facts[$ref], $refs))
            ."\n".implode("\n", array_map(fn (string $subject): string => (string) ($names[$subject] ?? ''), $about))."\n".$question;
        preg_match_all('~\d+(?:[.,]\d+)?~u', $text, $numbers);
        foreach ($numbers[0] as $number) {
            if (! str_contains($known, $number) && ! str_contains($known, str_replace(',', '.', $number))) {
                return 'number_not_in_facts';
            }
        }

        return null;
    }

    /**
     * Every fact by ref, and whom it belongs to: a product's ref, or "store".
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private static function index(array $sheet): array
    {
        $facts = [];
        $owner = [];
        foreach ($sheet['products'] as $product) {
            foreach ($product['facts'] as $fact) {
                $facts[$fact['ref']] = $fact['say'];
                $owner[$fact['ref']] = $product['ref'];
            }
        }
        foreach ($sheet['store'] as $fact) {
            $facts[$fact['ref']] = $fact['say'];
            $owner[$fact['ref']] = 'store';
        }

        return [$facts, $owner];
    }

    /** An approved fact as one line in the viewer's language: "מתח: 18 V", "סוג: מברגה", or a highlight's own sentence. */
    private static function factText(EnrichmentFact $fact): string
    {
        $value = trim($fact->valueLabel());

        if ($value === '') {
            return '';
        }

        return match ($fact->kind) {
            FactKind::Highlight, FactKind::Promise => $value,
            FactKind::Use => __('assistant::facts.use', ['use' => $value]),
            FactKind::Flag => trim($fact->keyLabel()),
            default => trim($fact->keyLabel()).': '.$value,
        };
    }

    /**
     * The product's own structured fields: category, the store's tags and kind, its attributes,
     * SKU, the options in stock. Never its description.
     *
     * @return list<string>
     */
    private static function fields(array $product, ?CatalogProduct $row): array
    {
        $out = [];

        if (($product['category'] ?? '') !== '') {
            $out[] = __('assistant::facts.category', ['category' => $product['category']]);
        }
        $type = trim((string) ($row?->payload['product_type'] ?? ''));
        if ($type !== '') {
            $out[] = __('assistant::facts.kind', ['kind' => $type]);
        }
        $tags = array_slice(array_values(array_filter(array_map(fn ($t): string => trim((string) $t), (array) ($row?->payload['tags'] ?? [])))), 0, 8);
        if ($tags !== []) {
            $out[] = __('assistant::facts.tags', ['tags' => implode(', ', $tags)]);
        }
        foreach (array_slice((array) ($product['attributes'] ?? []), 0, 8) as $attribute) {
            $out[] = (string) $attribute;
        }
        if (($product['sku'] ?? '') !== '') {
            $out[] = __('assistant::facts.sku', ['sku' => $product['sku']]);
        }
        if (($product['options_in_stock'] ?? []) !== []) {
            $out[] = __('assistant::facts.options_in_stock', ['options' => implode(', ', array_slice($product['options_in_stock'], 0, 12))]);
        }

        return $out;
    }

    /**
     * The store's own statements, one per line, from its knowledge entries only (the information on
     * every product page, the team's answers): never a page's or an article's passage.
     *
     * @param  array<string, array<string, mixed>>  $byRef
     * @return list<array{ref: string, say: string}>
     */
    private static function store(array $byRef): array
    {
        $lines = [];

        foreach ($byRef as $passage) {
            if (($passage['kind'] ?? null) !== 'knowledge') {
                continue;
            }
            // A team answer is a question and its answer, kept together; a list is its items.
            $parts = str_contains((string) $passage['text'], "\n\n")
                ? preg_split('/\n{2,}/u', (string) $passage['text'])
                : preg_split('/\n+|\s+-\s+/u', (string) $passage['text']);

            foreach ($parts ?: [] as $part) {
                $part = trim((string) preg_replace('/^[\s\-–•*]+|\s+/u', ' ', $part));
                if (mb_strlen($part) >= self::MIN_LINE && ! in_array($part, $lines, true)) {
                    $lines[] = $part;
                }
            }
        }

        return array_map(fn (string $say, int $i): array => ['ref' => 'k'.($i + 1), 'say' => $say], array_slice($lines, 0, self::STORE_LINES), array_keys(array_slice($lines, 0, self::STORE_LINES)));
    }
}
