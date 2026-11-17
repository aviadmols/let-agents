<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;

/**
 * Every store's own bank of the terms of its trade, so a question on one ("מה זה 14K?", "what is merino?", "מה ההבדל
 * בין GH ל-VS?") is answered from a checked line and not from what a writer recalls at the moment (as Aviad asked,
 * 16.11: for every store, from its own data, no word list).
 *
 * 1. Code gathers the words that may be terms from the store's own data: what its shoppers asked the meaning of, the
 *    short codes in its titles ("14K", "VS", "IPX7"), what a percentage is of ("100% כותנה"), the values its products
 *    are sold by, the words and pairs its titles repeat, its tags. Each counted by products; colours, numbers alone,
 *    codes and dates out. A word sent once is never sent again (terms['seen']).
 * 2. One family keeps those a shopper may not know and the trade agrees on, and says each in a sentence or two of
 *    general knowledge, against a schema; a model of the other family keeps only what is correct as written.
 * 3. Code drops a line with a sum, a phone, an email or a link, and saves the bank as the store's knowledge entry
 *    (BuildShopKnowledge::TERMS). The team edits a line or hides it; its line is never written over.
 *
 * The assistant reads only the lines whose term the question names (named()). In the scan and at night, with the
 * flag on. Every call asks SpendGuard first and is accounted on the run.
 */
final class BuildTradeGlossary
{
    public const AGENT = 'assistant.term_writer';

    public const ACTION = 'assistant.write_terms';

    // v2: what is measured and agreed only, never which is harder or better (Rocks, 16.11: the checker dropped 9K for it)
    // v3 (17.11): in plain words, what the eye sees, and where it stands on its scale, honestly both ways; written by the stronger model
    public const PROMPT_VERSION = 3;

    /** Words sent to the writer in one run, at most, and of one source. */
    private const CANDIDATES = 160;

    private const PER_SOURCE = 40;

    /** Products read for the words, at most. */
    private const PRODUCTS = 3000;

    /** The fewest products a word must be in to be a word of the store (a shopper's question needs no count). */
    private const MIN_PRODUCTS = 2;

    private const MIN_TITLE_PRODUCTS = 3;

    private const MAX_TERM = 60;

    private const MIN_TEXT = 20;

    private const MAX_TEXT = 450;

    private const EXAMPLE = 70;

    /** Questions read for what shoppers asked the meaning of. */
    private const ASKS = 2000;

    /** What a line told to shoppers never holds: a sum, a phone, an email, a link. */
    private const NOT_TOLD = '/₪|\$|€|ש["״]ח|\bnis\b|\+?\d(?:[\s-]?\d){8,}|@|https?:\/\/|www\./iu';

    /** A question asking what a word means, or how two differ: what follows is the word. */
    private const ASKS_MEANING = '/(?:^|\s)(?:מה\s+(?:זה|זאת|זו|הם|הן|ההבדל\s+בין|ההבדלים\s+בין|המשמעות\s+של|הפירוש\s+של)|מהו|מהי|מהם|מהן|what(?:\'s|\s+is|\s+are|\s+does)|difference\s+between|meaning\s+of)\s+(.+)$/iu';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
        private readonly BuildShopKnowledge $knowledge,
    ) {}

    /**
     * @param  bool  $trial  written while the store's flag is off, by an operator, to be tried in a quality run
     *                       (QualityBaselineCommand --terms) before shoppers read it
     */
    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual, bool $trial = false): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->write($run, $shopId, $trial)),
        );
    }

    private function write(RunContext $run, string $shopId, bool $trial): void
    {
        if (! $trial && ! Features::enabled('assistant.trade_terms', $shopId)) {
            $run->output(['skipped' => 'off'])->summary('assistant::runs.terms_off');

            return;
        }

        $writer = AgentModel::from('assistant.terms_writer_provider', 'assistant.terms_writer_model');
        $checker = AgentModel::from('assistant.terms_check_provider', 'assistant.terms_check_model');

        if ($writer->provider->family() === $checker->provider->family()) {
            $run->fail('assistant::runs.terms_same_family', ['family' => $writer->provider->label()]);

            return;
        }

        $state = $this->knowledge->termState();
        // decided with another prompt: written again, all but what the team wrote or hid
        if ($state['version'] !== self::PROMPT_VERSION) {
            $state['rows'] = array_values(array_filter($state['rows'], fn (array $r): bool => $r['state'] !== 'ai'));
            $state['seen'] = [];
        }
        $rows = $state['rows'];
        $seen = array_flip($state['seen']);
        foreach ($rows as $row) {
            $seen[self::key((string) $row['term'])] = true;
        }

        $fresh = array_values(array_filter(self::candidates(), fn (array $c): bool => ! isset($seen[self::key($c['term'])])));
        $fresh = array_slice($fresh, 0, self::CANDIDATES);
        $room = (int) Settings::get('assistant.terms_max', $shopId) - count(array_filter($rows, fn (array $r): bool => $r['state'] !== 'hidden'));
        $counts = ['candidates' => count($fresh), 'written' => 0, 'kept' => 0, 'terms' => count($rows)];

        if ($fresh === [] || $room <= 0) {
            $run->output($counts)->summary('assistant::runs.terms_same', ['terms' => (string) count($rows)]);

            return;
        }

        $shop = Shop::query()->whereKey($shopId)->first(['name', 'content_locale']);
        $locale = (string) ($shop?->content_locale ?: 'he');
        $sent = [];
        foreach ($fresh as $i => $candidate) {
            $sent['c'.($i + 1)] = $candidate;
        }

        try {
            $written = $this->call($run, $writer, 'terms_writer', 'trade_terms', [
                'store' => (string) ($shop?->name ?? ''),
                'language' => $locale,
                'sells' => self::sells(),
                'most' => $room,
                'candidates' => array_map(fn (string $id, array $c): array => ['id' => $id, 'term' => $c['term'], 'from' => $c['from'], 'products' => $c['products'], 'example' => $c['example']], array_keys($sent), $sent),
            ]);

            $lines = self::gate((array) ($written['terms'] ?? []), $sent, $room);
            $counts['written'] = count($lines);
            $keep = [];
            if ($lines !== []) {
                $checked = $this->call($run, $checker, 'terms_check', 'trade_terms_check', [
                    'language' => $locale,
                    'terms' => array_map(fn (string $id, array $l): array => ['id' => $id, 'term' => $l['term'], 'text' => $l['text']], array_keys($lines), $lines),
                ]);
                $keep = array_flip(array_map('strval', array_filter((array) ($checked['keep'] ?? []), 'is_scalar')));
            }
        } catch (SpendCapReached $e) {
            $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

            return;
        } catch (ModelCallFailed $e) {
            $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

            return;
        }

        $model = $writer->model.'+'.$checker->model.'@v'.self::PROMPT_VERSION;
        foreach (array_intersect_key($lines, $keep) as $line) {
            $rows[] = ['key' => self::key($line['term']), 'term' => $line['term'], 'text' => $line['text'], 'state' => 'ai', 'from' => $line['from'], 'model' => $model, 'at' => now()->toIso8601String()];
        }
        $counts['kept'] = count(array_intersect_key($lines, $keep));
        $counts['terms'] = count($rows);

        // every word sent is decided: kept, dropped by the checker, or not a term; none is sent again
        $this->knowledge->terms($rows, [...$state['seen'], ...array_map(fn (array $c): string => self::key($c['term']), array_values($sent))], self::PROMPT_VERSION);
        $run->output($counts)->summary('assistant::runs.terms_written', ['new' => (string) $counts['kept'], 'terms' => (string) count(array_filter($rows, fn (array $r): bool => $r['state'] !== 'hidden'))]);
    }

    /**
     * The writer's lines that code lets through: of a word that was sent, once each, its term as the store writes it,
     * one paragraph of fitting length with no sum, phone, email or link; at most $room.
     *
     * @param  array<int, mixed>  $terms  the writer's
     * @param  array<string, array{term: string, from: string, products: int, example: string}>  $sent  by id
     * @return array<string, array{term: string, text: string, from: string}> by the candidate's id
     */
    public static function gate(array $terms, array $sent, int $room): array
    {
        $lines = [];
        foreach ($terms as $item) {
            $id = is_array($item) ? (string) ($item['id'] ?? '') : '';
            if (! isset($sent[$id]) || isset($lines[$id]) || count($lines) >= $room) {
                continue;
            }
            $text = trim((string) preg_replace(['~https?://\S+|www\.\S+~iu', '/\s+/u'], ['', ' '], (string) ($item['text'] ?? '')));
            if (mb_strlen($text) < self::MIN_TEXT || mb_strlen($text) > self::MAX_TEXT || preg_match(self::NOT_TOLD, $text) === 1) {
                continue;
            }
            $lines[$id] = ['term' => $sent[$id]['term'], 'text' => $text, 'from' => $sent[$id]['from']];
        }

        return $lines;
    }

    /**
     * The words of the store's own data that may be terms of its trade, the likeliest first: what shoppers asked the
     * meaning of, short codes in its titles, what a percentage is of, the values its products are sold by, the words
     * and pairs its titles repeat, its tags. Each once, counted by products. Sorted explicitly, so the same store gives
     * the same words whatever order the database returns. Inside the shop's tenant. Code only.
     *
     * @return list<array{term: string, from: string, products: int, example: string}>
     */
    public static function candidates(): array
    {
        $found = ['asked' => [], 'code' => [], 'percent' => [], 'value' => [], 'title' => [], 'tag' => []];
        $add = function (string $source, string $term, string $example, string $product) use (&$found): void {
            // never trim() with a multibyte list: it cuts Hebrew letters apart
            $term = trim((string) preg_replace(['/^[\s\-–—:;,.!?"\'״׳()\[\]]+|[\s\-–—:;,.!?"\'״׳()\[\]]+$/u', '/\s+/u'], ['', ' '], $term));
            if (! self::mayBeTerm($term)) {
                return;
            }
            $key = self::key($term);
            $found[$source][$key] ??= ['term' => $term, 'products' => [], 'example' => mb_substr(trim($example), 0, self::EXAMPLE)];
            $found[$source][$key]['products'][$product] = true;
        };

        $products = CatalogProduct::query()->active()->orderBy('external_id')->limit(self::PRODUCTS)->get(['external_id', 'title', 'payload']);
        foreach ($products as $product) {
            $id = (string) $product->external_id;
            $title = trim((string) $product->title);
            $payload = (array) $product->payload;
            $text = $title.' '.trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($payload['short_description'] ?? '').' '.(string) ($payload['description'] ?? '')), ENT_QUOTES | ENT_HTML5)));

            // a short code among the words: letters with a digit or in capitals ("14K", "VS", "GH", "IPX7", "MDF")
            preg_match_all('/(?<![\p{L}\p{N}])(?=[A-Za-z0-9+\/-]*[A-Za-z])(?=[A-Za-z0-9+\/-]*[A-Z0-9])[A-Za-z0-9][A-Za-z0-9+\/-]{1,9}(?![\p{L}\p{N}])/u', $text, $codes);
            foreach (array_unique($codes[0]) as $code) {
                if (preg_match('/^[A-Z]?[a-z]+$/', $code) !== 1) {
                    $add('code', $code, $title, $id);
                }
            }
            // what a percentage is of: "100% כותנה", "70% bamboo"
            preg_match_all('/\d{1,3}\s?%\s*(\p{L}[\p{L}\'׳-]+)/u', $text, $percents);
            foreach (array_unique($percents[1]) as $material) {
                $add('percent', $material, $title, $id);
            }
            // the values it is sold by: its attributes and options
            foreach ([...(array) ($payload['attributes'] ?? []), ...(array) ($payload['options'] ?? [])] as $attribute) {
                $values = is_array($attribute) ? ($attribute['options'] ?? $attribute['values'] ?? $attribute['value'] ?? []) : $attribute;
                foreach ((array) $values as $value) {
                    if (is_scalar($value)) {
                        $add('value', (string) $value, $title, $id);
                    }
                }
            }
            foreach ((array) ($payload['tags'] ?? []) as $tag) {
                if (is_scalar($tag)) {
                    $add('tag', (string) $tag, $title, $id);
                }
            }
            // the words its titles repeat, and pairs of them
            $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}\'׳"״-]+/u', $title) ?: [], fn (string $w): bool => mb_strlen($w) >= 3 && preg_match('/\p{L}/u', $w) === 1));
            foreach (array_unique($words) as $word) {
                $add('title', $word, $title, $id);
            }
            for ($i = 0; $i + 1 < count($words); $i++) {
                $add('title', $words[$i].' '.$words[$i + 1], $title, $id);
            }
        }

        // what shoppers asked the meaning of: no count needed, a shopper asked
        $questions = [
            ...AssistantSearchAsk::query()->orderByDesc('day')->orderBy('id')->limit(self::ASKS)->pluck('question')->all(),
            ...AssistantAnswer::query()->whereNull('product_id')->orderByDesc('asked_count')->orderBy('id')->limit(self::ASKS)->pluck('question')->all(),
        ];
        foreach ($questions as $question) {
            foreach (self::askedTerms((string) $question) as $term) {
                $add('asked', $term, (string) $question, 'q:'.md5((string) $question));
            }
        }

        $out = [];
        foreach ($found as $source => $terms) {
            $least = match ($source) {
                'asked' => 1,
                'title' => self::MIN_TITLE_PRODUCTS,
                default => self::MIN_PRODUCTS,
            };
            $kept = array_filter($terms, fn (array $t): bool => count($t['products']) >= $least);
            // the most products first; plain words among them ("טבעת", "ring") are left out by the writer, never by a list
            uasort($kept, fn (array $a, array $b): int => [count($b['products']), $a['term']] <=> [count($a['products']), $b['term']]);
            foreach (array_slice($kept, 0, self::PER_SOURCE, true) as $key => $term) {
                if (! isset($out[$key])) {
                    $out[$key] = ['term' => $term['term'], 'from' => $source, 'products' => count($term['products']), 'example' => $term['example']];
                }
            }
        }

        return array_values($out);
    }

    /**
     * The words a question asks the meaning of: "מה זה 14K?" gives 14K; "מה ההבדל בין GH ל-VS?" gives GH and VS;
     * "what is merino wool" gives merino wool. At most four words each.
     *
     * @return list<string>
     */
    public static function askedTerms(string $question): array
    {
        $question = trim((string) preg_replace('/\s+/u', ' ', $question));
        if (preg_match(self::ASKS_MEANING, $question, $m) !== 1) {
            return [];
        }
        $rest = trim((string) preg_replace('/\s*[?？!.].*$/u', '', $m[1]));
        $rest = (string) preg_replace('/^(?:a|an|the)\s+/iu', '', $rest);
        // "בין X ל-Y", "between X and Y": both
        $parts = preg_split('/\s+(?:ל-?|לבין\s+|and\s+|vs\.?\s+|או\s+|or\s+)(?=\S)|\s*,\s*/u', $rest) ?: [$rest];
        $terms = [];
        foreach ($parts as $part) {
            $part = trim((string) preg_replace('/^(?:ה|ל-?)(?=[A-Za-z0-9])/u', '', trim($part)));
            $part = (string) preg_replace('/\s+(?:mean|means|in\s+.+|ב.+)$/iu', '', $part);
            $words = preg_split('/\s+/u', $part) ?: [];
            if ($part !== '' && count($words) <= 4) {
                $terms[] = $part;
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * The bank's lines whose term the question names, each of the term's words said in it, with its letters' final forms
     * and a prefix letter taken as the same ("יהלומי מעבדה" names "יהלום מעבדה"; "14k" names "14K"). Lines are "- term: text".
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function named(string $question, array $lines): array
    {
        $plain = fn (string $s): string => strtr(mb_strtolower($s), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ', '״' => '"', '׳' => "'"]);
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}\'"]+/u', $plain($question)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $words[] = $word;
            if (mb_strlen($word) > 3 && preg_match('/^[ובהלמשכ]/u', $word) === 1) {
                $words[] = mb_substr($word, 1);
            }
        }
        $says = function (string $termWord) use ($words): bool {
            foreach ($words as $word) {
                if ($word === $termWord || (mb_strlen($termWord) >= 3 && str_starts_with($word, $termWord)) || (mb_strlen($word) >= 4 && str_starts_with($termWord, $word))) {
                    return true;
                }
            }

            return false;
        };

        return array_values(array_filter($lines, function (string $line) use ($plain, $says): bool {
            if (preg_match('/^-\s*(.+?):\s/u', $line, $m) !== 1) {
                return false;
            }
            $termWords = array_values(array_filter(preg_split('/[^\p{L}\p{N}\'"]+/u', $plain($m[1])) ?: [], fn (string $w): bool => $w !== ''));

            return $termWords !== [] && array_filter($termWords, fn (string $w): bool => ! $says($w)) === [];
        }));
    }

    /** One spelling of a term for "the same": lower case, no quotes, one space. */
    public static function key(string $term): string
    {
        return trim((string) preg_replace(['/["\'״׳]/u', '/\s+/u'], ['', ' '], mb_strtolower($term)));
    }

    /** Not a term: too short or long, no letter, a colour, a date, a code of the system, an address. */
    private static function mayBeTerm(string $term): bool
    {
        $length = mb_strlen($term);

        return $length >= 2 && $length <= self::MAX_TERM
            && preg_match('/\p{L}/u', $term) === 1
            // a measure is a number and its unit apart ("5 ליטר", "25 מ\"מ", "159 cm") or numbers by numbers ("6x40", "0-3");
            // a code written as one ("14K", "925") may be a term
            && preg_match('/^#?[0-9a-f]{6,}$|^\d{4}-\d{2}-\d{2}|https?:|@|^\d+(?:[.,x×*\/-]\d+)*(?:\s+\p{L}{1,6}(?:["״\'׳.]\p{L}{0,2})?)?$/iu', $term) !== 1;
    }

    /** What the store sells, as the writer is told: its most common categories. */
    private static function sells(): array
    {
        $count = [];
        foreach (CatalogProduct::query()->active()->orderBy('external_id')->limit(self::PRODUCTS)->pluck('payload') as $payload) {
            foreach ((array) (((array) $payload)['categories'] ?? []) as $category) {
                $name = is_array($category) ? (string) ($category['name'] ?? '') : (is_scalar($category) ? (string) $category : '');
                if ($name !== '') {
                    $count[$name] = ($count[$name] ?? 0) + 1;
                }
            }
        }
        uksort($count, fn (string $a, string $b): int => [$count[$b], $a] <=> [$count[$a], $b]);

        return array_slice(array_keys($count), 0, 12);
    }

    /**
     * One model call, its spend checked first and accounted after.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function call(RunContext $run, AgentModel $role, string $settings, string $prompt, array $input): array
    {
        $system = (string) file_get_contents(__DIR__.'/../Prompts/'.$prompt.'.v'.self::PROMPT_VERSION.'.md');
        $schema = json_decode((string) @file_get_contents(__DIR__.'/../Prompts/'.$prompt.'.v'.self::PROMPT_VERSION.'.schema.json'), true);
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $in = (float) Settings::get("assistant.{$settings}_input_usd_per_million");
        $out = (float) Settings::get("assistant.{$settings}_output_usd_per_million");
        $maxOutput = (int) Settings::get("assistant.{$settings}_max_output_tokens");

        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);
        $reply = is_array($schema) && $this->models instanceof StructuredChatModel
            ? $this->models->structured($role->provider, $role->model, $system, $user, $schema, $maxOutput)
            : $this->models->json($role->provider, $role->model, $system, $user, $maxOutput);
        $run->usage($role->provider->value, $role->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply->data;
    }
}
