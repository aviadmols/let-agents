<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Models\EnrichmentRelationRules;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Relations\RelationRuleSet;
use App\Modules\Retrieval\Enums\MatchKind;
use App\Modules\Retrieval\Enums\MatchStatus;
use App\Modules\Retrieval\Models\RetrievalMatch;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What to show with each product, in code, from approved facts and the store's own links.
 * Every relation keeps the reasons it was chosen, for the log and for tuning.
 *
 *   complement   the merchant's cross-sells; products whose merchant links point to this one;
 *                the shop's rules (a battery of the same brand and voltage for a cordless tool,
 *                oil for wood meant for outdoor jobs)
 *   family       the same product in other sizes (see ProductFamily)
 *   alternative  the same product type and power source, at a similar price
 *   both         what the matching model chose from code's candidates and code accepted
 *                (Retrieval), with the model's few words of why
 *
 * Stock is checked here and again, live, in the widget.
 */
final class ComputeProductRelations
{
    public const AGENT = 'enrichment.relator';

    public const ACTION = 'enrichment.compute_relations';

    private const MERCHANT_LIMIT = 6;

    private const RULE_LIMIT = 4;

    private const FAMILY_LIMIT = 12;

    private const ALTERNATIVE_LIMIT = 8;

    /** Alternatives cost between 60% and 160% of the product. */
    private const PRICE_BAND = [0.6, 1.6];

    private const SAMPLES = 25;

    /** Merchant links from one category to another, from this many products, make the pair a habit of the store. */
    private const AFFINITY_MIN_LINKS = 3;

    /** Of the products of one kind that link anywhere, this share must link to the same kind. */
    private const AFFINITY_MIN_SHARE = 0.25;

    private const AFFINITY_LIMIT = 3;

    /** A product with fewer complements than this gets its category's habitual partners. */
    private const AFFINITY_WHEN_FEWER_THAN = 2;

    /** Past this many partners a product's complements stop being a suggestion and become a list. */
    private const TOGETHER_LIMIT = 4;

    /** An order with more lines than this is a restock, not a decision about what goes together. */
    private const TOGETHER_MAX_LINES = 12;

    /** Below the merchant's cross-sells (150) and what was bought together (200), above the rules (100). */
    private const MODEL_COMPLEMENT_SCORE = 120;

    /** Between a shared vocabulary type (100) and a shared store category (80). */
    private const MODEL_ALTERNATIVE_SCORE = 95;

    /** @var array<string, array<string, mixed>> key "product|related|kind" => row */
    private array $rows = [];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->compute($run, $shopId)),
        );
    }

    private function compute(RunContext $run, string $shopId): void
    {
        $this->rows = [];
        $products = CatalogProduct::query()->whereNull('removed_at')->get(['id', 'external_id', 'title', 'price', 'in_stock', 'purchasable', 'type', 'payload']);
        $profiles = $this->profiles($products);
        $byExternal = $products->keyBy('external_id');
        $ruleStats = [];

        // 1. The merchant's own cross-sells, in the merchant's order.
        foreach ($products as $product) {
            $position = 0;
            foreach ($product->merchantRelations() as $relation) {
                $related = $byExternal->get($relation['target']);

                if ($relation['type'] === 'cross_sell' && $related !== null && $related->id !== $product->id && $position < self::MERCHANT_LIMIT) {
                    $this->add($product->id, $related->id, RelationKind::Complement, 'merchant', 150 - $position++, ['merchant' => 'cross_sell']);
                }
            }
        }

        // 2. Merchant links pointing the other way: a battery that lists this tool as an upsell.
        $reverse = [];
        foreach ($products as $product) {
            foreach ($product->merchantRelations() as $relation) {
                $target = $byExternal->get($relation['target']);

                if ($target !== null && $target->id !== $product->id) {
                    $reverse[$target->id][] = [$product, $relation['type']];
                }
            }
        }

        foreach ($reverse as $productId => $links) {
            foreach (array_slice($links, 0, self::MERCHANT_LIMIT) as $i => [$linking, $type]) {
                $this->add($productId, $linking->id, RelationKind::Complement, 'merchant_reverse', 110 - $i, ['merchant' => 'links_to_this', 'link' => $type]);
            }
        }

        // 2b. What shoppers actually bought together. Evidence rather than anybody's opinion,
        // so it outranks both the merchant's pairings and the rules.
        $ruleStats['bought_together'] = $this->applyBoughtTogether($shopId, $byExternal);

        // 2c. What the matching model chose from code's candidates, after code checked every pick
        // (Retrieval). A judgement on evidence, so below the evidence itself and the merchant's
        // own pairings, above the rules' general patterns.
        $ruleStats['ai_match'] = $this->applyModelMatches($products->keyBy('id'));

        // 3. The shop's rules.
        $rules = EnrichmentRelationRules::query()->where('active', true)->orderByDesc('version')->first();

        foreach ($rules?->rules()->rules() ?? [] as $rule) {
            $ruleStats[$rule['key']] = $this->applyRule($rule, $profiles);
        }

        // 3b. What the store itself pairs, for products the rules above left with nothing. It is a
        // habit, not knowledge, so it never contradicts a rule.
        $ruleStats['category_affinity'] = $this->applyCategoryAffinity($products, $profiles, $rules?->rules()->rules() ?? []);

        // 4. Other sizes of the same product.
        $families = collect($profiles)->filter(fn (array $p): bool => $p['family'] !== null)->groupBy('family');

        foreach ($families as $members) {
            if ($members->count() < 2) {
                continue;
            }

            $sorted = $members->sortBy('title', SORT_NATURAL)->values();

            foreach ($sorted as $member) {
                foreach ($sorted->where('id', '!=', $member['id'])->take(self::FAMILY_LIMIT)->values() as $i => $other) {
                    $this->add($member['id'], $other['id'], RelationKind::Family, 'family', 100 - $i, ['family' => $member['family_name']]);
                }
            }
        }

        // 5. Alternatives: same vocabulary, type and power source, similar price, not the same family.
        $groups = collect($profiles)
            ->filter(fn (array $p): bool => $p['type'] !== null && $p['type'] !== 'other' && $p['price'] > 0)
            ->groupBy(fn (array $p): string => $p['vocabulary'].'|'.$p['type'].'|'.($p['choices']['power_source'] ?? '-'));

        foreach ($groups as $group) {
            foreach ($group as $profile) {
                $candidates = $group
                    ->filter(fn (array $o): bool => $o['id'] !== $profile['id'] && ($o['family'] === null || $o['family'] !== $profile['family']))
                    ->map(fn (array $o): array => $o + ['ratio' => $o['price'] / $profile['price']])
                    ->filter(fn (array $o): bool => $o['ratio'] >= self::PRICE_BAND[0] && $o['ratio'] <= self::PRICE_BAND[1])
                    ->sortBy(fn (array $o): array => [abs(log($o['ratio'])), $o['external_id']])
                    ->take(self::ALTERNATIVE_LIMIT)
                    ->values();

                foreach ($candidates as $o) {
                    $this->add($profile['id'], $o['id'], RelationKind::Alternative, 'same_type', 100 - (int) round(abs(log($o['ratio'])) * 100), [
                        'type' => $profile['type'],
                        'power_source' => $profile['choices']['power_source'] ?? null,
                        'price_ratio' => round($o['ratio'], 2),
                    ]);
                }
            }
        }

        // 5b. Alternatives for products no vocabulary reads yet: the same deepest store category,
        // similar price, not the same family. Weaker than a shared type, so it never outranks one.
        $byLeaf = collect($profiles)
            ->filter(fn (array $p): bool => $p['type'] === null && $p['leaf'] !== null && $p['price'] > 0)
            ->groupBy('leaf');

        foreach ($byLeaf as $group) {
            foreach ($group as $profile) {
                $candidates = $group
                    ->filter(fn (array $o): bool => $o['id'] !== $profile['id'] && ($o['family'] === null || $o['family'] !== $profile['family']))
                    ->map(fn (array $o): array => $o + ['ratio' => $o['price'] / $profile['price']])
                    ->filter(fn (array $o): bool => $o['ratio'] >= self::PRICE_BAND[0] && $o['ratio'] <= self::PRICE_BAND[1])
                    ->sortBy(fn (array $o): array => [abs(log($o['ratio'])), $o['external_id']])
                    ->take(self::ALTERNATIVE_LIMIT)
                    ->values();

                foreach ($candidates as $o) {
                    $this->add($profile['id'], $o['id'], RelationKind::Alternative, 'same_category', 80 - (int) round(abs(log($o['ratio'])) * 100), [
                        'category' => $profile['leaf_name'],
                        'price_ratio' => round($o['ratio'], 2),
                    ]);
                }
            }
        }

        $now = now();

        DB::transaction(function () use ($shopId, $now): void {
            EnrichmentProductRelation::query()->delete();

            foreach (array_chunk(array_values($this->rows), 500) as $chunk) {
                EnrichmentProductRelation::query()->insert(array_map(fn (array $row): array => [
                    'id' => (string) Str::ulid(),
                    'shop_id' => $shopId,
                    'product_id' => $row['product_id'],
                    'related_product_id' => $row['related_product_id'],
                    'kind' => $row['kind'],
                    'source' => $row['source'],
                    'score' => $row['score'],
                    'reasons' => json_encode($row['reasons'], JSON_UNESCAPED_UNICODE),
                    'computed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        $rows = collect($this->rows);
        $titles = $products->pluck('title', 'id');

        $run->output([
            'relations' => $rows->count(),
            'by_kind' => $rows->countBy('kind')->all(),
            'by_source' => $rows->countBy('source')->all(),
            'products_with_complements' => $rows->where('kind', RelationKind::Complement->value)->pluck('product_id')->unique()->count(),
            'rules_version' => $rules?->version,
            'rules' => $ruleStats,
            'samples' => $rows->where('kind', RelationKind::Complement->value)->take(self::SAMPLES)->map(fn (array $row): array => [
                'product' => mb_substr((string) $titles->get($row['product_id']), 0, 60),
                'related' => mb_substr((string) $titles->get($row['related_product_id']), 0, 60),
                'source' => $row['source'],
                'reasons' => $row['reasons'],
            ])->values()->all(),
        ])->summary('enrichment::runs.relations_computed', [
            'relations' => number_format($rows->count()),
            'complements' => number_format($rows->where('kind', RelationKind::Complement->value)->count()),
            'products' => number_format($rows->where('kind', RelationKind::Complement->value)->pluck('product_id')->unique()->count()),
            'families' => number_format($rows->where('kind', RelationKind::Family->value)->count()),
            'alternatives' => number_format($rows->where('kind', RelationKind::Alternative->value)->count()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, array<string, mixed>>  $profiles
     * @return array{from: int, related: int}
     */
    private function applyRule(array $rule, array $profiles): array
    {
        $from = array_filter($profiles, fn (array $p): bool => RelationRuleSet::meets((array) $rule['from'], $p));
        $to = array_filter($profiles, fn (array $p): bool => $p['in_stock'] && RelationRuleSet::meets((array) $rule['to'], $p));
        $kind = RelationKind::from($rule['kind']);
        $limit = (int) ($rule['limit'] ?? self::RULE_LIMIT);
        $match = (array) ($rule['match'] ?? []);
        $related = 0;

        foreach ($from as $product) {
            $boosted = isset($rule['boost']['from_choices']) && RelationRuleSet::meets(['choices' => $rule['boost']['from_choices']], $product);
            $candidates = [];

            foreach ($to as $candidate) {
                if ($candidate['id'] === $product['id'] || ($candidate['family'] !== null && $candidate['family'] === $product['family'])) {
                    continue;
                }

                $reasons = $this->matches($match, $product, $candidate);

                if ($reasons === null) {
                    continue;
                }

                $candidates[] = [$candidate, $reasons];
            }

            usort($candidates, fn (array $a, array $b): int => [$b[1]['_strength'], $a[0]['price'], $a[0]['external_id']] <=> [$a[1]['_strength'], $b[0]['price'], $b[0]['external_id']]);

            foreach (array_slice($candidates, 0, $limit) as $i => [$candidate, $reasons]) {
                unset($reasons['_strength']);
                $this->add($product['id'], $candidate['id'], $kind, $rule['key'], ($boosted ? 140 : 100) - $i, ['rule' => $rule['key']] + $reasons);
                $related++;
            }
        }

        return ['from' => count($from), 'related' => $related];
    }

    /**
     * The conditions a rule sets between two products, or null when one fails.
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>|null
     */
    private function matches(array $match, array $a, array $b): ?array
    {
        $reasons = ['_strength' => 0];

        if (($match['brand'] ?? false) === true) {
            if ($a['brand'] === null || $b['brand'] === null || mb_strtolower($a['brand']) !== mb_strtolower($b['brand'])) {
                return null;
            }
            $reasons['brand'] = $a['brand'];
            $reasons['_strength']++;
        }

        foreach ((array) ($match['specs'] ?? []) as $key) {
            $x = $a['specs'][$key] ?? null;
            $y = $b['specs'][$key] ?? null;

            if ($x === null || $y === null || abs($x - $y) > 0.02 * max(abs($x), abs($y))) {
                return null;
            }
            $reasons[$key] = $x;
            $reasons['_strength']++;
        }

        // A charger may not state its voltage; when both do, they must agree.
        foreach ((array) ($match['specs_if_known'] ?? []) as $key) {
            $x = $a['specs'][$key] ?? null;
            $y = $b['specs'][$key] ?? null;

            if ($x !== null && $y !== null) {
                if (abs($x - $y) > 0.02 * max(abs($x), abs($y))) {
                    return null;
                }
                $reasons[$key] = $x;
                $reasons['_strength']++;
            }
        }

        if (($match['uses'] ?? false) === true) {
            $shared = array_values(array_intersect((array) $a['uses'], (array) $b['uses']));

            if ($shared === []) {
                return null;
            }
            $reasons['uses'] = $shared;
            $reasons['_strength'] += count($shared);
        }

        return $reasons;
    }

    /**
     * Each product as the rules see it: approved facts and code's reading.
     *
     * @param  Collection<int, CatalogProduct>  $products
     * @return array<string, array<string, mixed>>
     */
    private function profiles(Collection $products): array
    {
        $vocabularyKeys = EnrichmentVocabulary::query()->pluck('key', 'id');
        $readings = EnrichmentCodeReading::query()->get(['product_id', 'vocabulary_id', 'brand', 'family_key', 'reading'])->keyBy('product_id');
        $profiles = [];

        foreach ($products as $product) {
            $reading = $readings->get($product->id);
            $leaf = self::leaf($product);

            $profiles[$product->id] = [
                'id' => $product->id,
                'external_id' => $product->external_id,
                'title' => $product->title,
                'price' => (float) ($product->price ?? 0),
                'in_stock' => $product->in_stock && $product->purchasable,
                'vocabulary' => $reading?->vocabulary_id === null ? null : $vocabularyKeys->get($reading->vocabulary_id),
                'brand' => $reading?->brand,
                'family' => $reading?->family_key,
                'family_name' => $reading?->reading['family']['name'] ?? null,
                'type' => null,
                'choices' => [],
                'specs' => [],
                'uses' => [],
                'categories' => array_values(array_filter(array_map(fn ($c): string => is_array($c) ? (string) ($c['id'] ?? '') : '', (array) ($product->payload['categories'] ?? [])))),
                // The deepest store category below the top level: what the product is, as the store files it.
                'leaf' => $leaf['id'] ?? null,
                'leaf_name' => $leaf['name'] ?? null,
            ];
        }

        EnrichmentFact::query()
            ->whereNotNull('product_id')
            ->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::Type, FactKind::Choice, FactKind::Spec, FactKind::Use])
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function (EnrichmentFact $fact) use (&$profiles, $vocabularyKeys): void {
                if (! isset($profiles[$fact->product_id])) {
                    return;
                }

                $profile = &$profiles[$fact->product_id];
                $profile['vocabulary'] ??= $fact->vocabulary_id === null ? null : $vocabularyKeys->get($fact->vocabulary_id);

                match ($fact->kind) {
                    FactKind::Type => $profile['type'] = (string) $fact->value_text,
                    FactKind::Choice => $profile['choices'][$fact->key] = (string) $fact->value_text,
                    FactKind::Spec => $profile['specs'][$fact->key] = (float) $fact->value_number,
                    default => $profile['uses'][] = (string) $fact->value_text,
                };
            });

        return $profiles;
    }

    /**
     * Category pairs the store links often (cross-sells and upsells, either way), and for each
     * product of the first category with few complements, the most linked products of the second.
     *
     * @param  Collection<int, CatalogProduct>  $products
     * @param  array<string, array<string, mixed>>  $profiles
     * @return array{pairs: int, from: int, related: int}
     */
    /**
     * What shoppers actually bought in the same order.
     *
     * Every other source here is somebody's opinion about what goes together: the merchant's
     * cross-sells, a rule written for the trade, a habit read off the merchant's own links. This
     * one is what happened. A pair that keeps appearing in the same basket is the strongest thing
     * the shop knows about itself, so it outranks all of them.
     *
     * It is deliberately blunt. Two products in one order, counted; below the shop's threshold it
     * is a coincidence and nothing is written. A basket with more lines than a person decides on
     * at once is a restock rather than a decision about what goes with what, and is skipped —
     * otherwise a single trade order would pair everything in it with everything else.
     *
     * Nothing about the shopper is read, and nothing leaves the shop: an order here is a list of
     * product ids and nothing more.
     *
     * @param  Collection<string, CatalogProduct>  $byExternal
     * @return array<string, int>
     */
    private function applyBoughtTogether(string $shopId, Collection $byExternal): array
    {
        $days = (int) Settings::get('enrichment.copurchase_window_days', $shopId);
        $need = (int) Settings::get('enrichment.copurchase_min_orders', $shopId);

        $pairs = [];
        $orders = 0;

        AnalyticsOrder::query()
            ->where('ordered_at', '>=', now()->subDays($days))
            // chunkById needs the key it chunks by, so it is selected with the basket.
            ->select(['id', 'items'])
            ->chunkById(500, function (Collection $chunk) use (&$pairs, &$orders, $byExternal): void {
                foreach ($chunk as $order) {
                    $ids = collect((array) $order->items)
                        ->pluck('product_id')
                        ->map(fn ($id): string => (string) $id)
                        ->filter(fn (string $id): bool => $byExternal->has($id))
                        ->unique()
                        ->values();

                    if ($ids->count() < 2 || $ids->count() > self::TOGETHER_MAX_LINES) {
                        continue;
                    }

                    $orders++;

                    // Every unordered pair in the basket, counted once.
                    foreach ($ids as $i => $one) {
                        foreach ($ids->slice($i + 1) as $other) {
                            $key = $one < $other ? $one.'|'.$other : $other.'|'.$one;
                            $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                        }
                    }
                }
            });

        arsort($pairs);
        $written = 0;
        $perProduct = [];

        foreach ($pairs as $key => $count) {
            if ($count < $need) {
                break;
            }

            [$one, $other] = explode('|', $key);

            foreach ([[$one, $other], [$other, $one]] as [$from, $to]) {
                if (($perProduct[$from] ?? 0) >= self::TOGETHER_LIMIT) {
                    continue;
                }

                $product = $byExternal->get($from);
                $related = $byExternal->get($to);

                if ($product === null || $related === null || ! $related->in_stock || ! $related->purchasable) {
                    continue;
                }

                $perProduct[$from] = ($perProduct[$from] ?? 0) + 1;
                $this->add($product->id, $related->id, RelationKind::Complement, 'bought_together', 200 - ($perProduct[$from] - 1), ['bought_together' => $count]);
                $written++;
            }
        }

        return ['orders' => $orders, 'pairs' => count($pairs), 'written' => $written];
    }

    /**
     * Picks the matching model made and code accepted. Stock is checked again here: a match
     * accepted last night may be sold out tonight.
     *
     * @param  Collection<string, CatalogProduct>  $byId
     * @return array<string, int>
     */
    private function applyModelMatches(Collection $byId): array
    {
        $written = ['complement' => 0, 'alternative' => 0];

        $matches = RetrievalMatch::query()
            ->where('status', MatchStatus::Accepted)
            ->whereNotNull('related_product_id')
            ->orderBy('product_id')->orderBy('kind')->orderBy('position')
            ->get(['product_id', 'related_product_id', 'kind', 'position', 'reason', 'signals']);

        foreach ($matches as $match) {
            $related = $byId->get($match->related_product_id);

            if (! $byId->has($match->product_id) || $related === null || ! $related->in_stock || ! $related->purchasable) {
                continue;
            }

            $complement = $match->kind === MatchKind::Complement;
            $this->add(
                $match->product_id,
                $related->id,
                $complement ? RelationKind::Complement : RelationKind::Alternative,
                'ai_match',
                ($complement ? self::MODEL_COMPLEMENT_SCORE : self::MODEL_ALTERNATIVE_SCORE) - $match->position,
                array_filter(['ai_match' => $match->reason ?? '', 'evidence' => $match->signals]),
            );
            $written[$match->kind->value]++;
        }

        return $written;
    }

    private function applyCategoryAffinity(Collection $products, array $profiles, array $rules): array
    {
        $byExternal = $products->keyBy('external_id');
        $group = fn (array $profile): ?string => $profile['type'] !== null && $profile['type'] !== 'other'
            ? 't:'.$profile['vocabulary'].'|'.$profile['type']
            : ($profile['leaf'] === null ? null : 'c:'.$profile['leaf']);

        $pairs = [];
        $popularity = [];
        $linkers = [];

        foreach ($products as $product) {
            $profile = $profiles[$product->id] ?? null;
            $from = $profile === null ? null : $group($profile);
            $links = $product->merchantRelations();

            if ($from !== null && $links !== []) {
                $linkers[$from][$product->id] = true;
            }

            foreach ($links as $relation) {
                $target = $byExternal->get($relation['target']);
                $targetProfile = $target === null ? null : ($profiles[$target->id] ?? null);
                $to = $targetProfile === null ? null : $group($targetProfile);

                if ($from === null || $to === null || $from === $to) {
                    continue;
                }

                $pairs[$from][$to][$product->id] = true;
                $popularity[$target->id] = ($popularity[$target->id] ?? 0) + 1;
            }
        }

        $complements = [];
        foreach ($this->rows as $row) {
            if ($row['kind'] === RelationKind::Complement->value) {
                $complements[$row['product_id']] = ($complements[$row['product_id']] ?? 0) + 1;
            }
        }

        $gates = $this->complementGates($rules, $profiles);
        $stats = ['pairs' => 0, 'weak' => 0, 'refused_by_rule' => 0, 'from' => 0, 'related' => 0];
        $byGroup = collect($profiles)->filter(fn (array $p): bool => $group($p) !== null)->groupBy($group);

        foreach ($pairs as $from => $targets) {
            foreach ($targets as $to => $linking) {
                // A habit, not a coincidence: enough products of this kind link there, and they are
                // a real share of the ones that link anywhere.
                $share = count($linking) / max(1, count($linkers[$from] ?? []));

                if (count($linking) < self::AFFINITY_MIN_LINKS || $share < self::AFFINITY_MIN_SHARE) {
                    $stats['weak']++;

                    continue;
                }

                $stats['pairs']++;
                $partners = $byGroup->get($to, collect())
                    ->filter(fn (array $p): bool => $p['in_stock'])
                    ->sortBy(fn (array $p): array => [-($popularity[$p['id']] ?? 0), $p['external_id']])
                    ->take(self::AFFINITY_LIMIT)
                    ->values();

                foreach ($byGroup->get($from, collect()) as $profile) {
                    if (($complements[$profile['id']] ?? 0) >= self::AFFINITY_WHEN_FEWER_THAN || $partners->isEmpty()) {
                        continue;
                    }

                    $stats['from']++;
                    foreach ($partners as $i => $partner) {
                        if ($partner['id'] === $profile['id']) {
                            continue;
                        }

                        // A rule that says when this kind of product is a complement has the last
                        // word: no battery for a tool with a cable, whatever the store links.
                        if (self::refusedByRule($gates, $profile, $partner['id'])) {
                            $stats['refused_by_rule']++;

                            continue;
                        }

                        $stats['related']++;
                        $this->add($profile['id'], $partner['id'], RelationKind::Complement, 'category_affinity', 70 - $i, [
                            'affinity' => [$profile['leaf_name'] ?? $profile['type'], $partner['leaf_name'] ?? $partner['type']],
                            'links' => count($linking),
                            'share' => round($share, 2),
                        ]);
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Which products only some products may be offered, and to whom. For every complement rule,
     * the products its "to" side picks out are gated: only a product the rule's "from" side accepts
     * may be offered one. A product no rule hands out is not gated at all.
     *
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, array<string, mixed>>  $profiles
     * @return list<array{to: array<string, bool>, from: array<string, mixed>}>
     */
    private function complementGates(array $rules, array $profiles): array
    {
        $gates = [];

        foreach ($rules as $rule) {
            if (($rule['kind'] ?? '') !== RelationKind::Complement->value) {
                continue;
            }

            $to = [];
            foreach ($profiles as $id => $profile) {
                if (RelationRuleSet::meets((array) $rule['to'], $profile)) {
                    $to[$id] = true;
                }
            }

            if ($to !== []) {
                $gates[] = ['to' => $to, 'from' => (array) $rule['from']];
            }
        }

        return $gates;
    }

    /**
     * @param  list<array{to: array<string, bool>, from: array<string, mixed>}>  $gates
     * @param  array<string, mixed>  $profile
     */
    private static function refusedByRule(array $gates, array $profile, string $partnerId): bool
    {
        $gated = false;

        foreach ($gates as $gate) {
            if (! isset($gate['to'][$partnerId])) {
                continue;
            }

            // Some rule hands this kind of product out; this product must qualify for one of them.
            $gated = true;

            if (RelationRuleSet::meets($gate['from'], $profile)) {
                return false;
            }
        }

        return $gated;
    }

    /**
     * The product's deepest store category with a parent (a top-level one such as "sale" says
     * nothing about what it is), as {id, name}, or null.
     *
     * @return array{id: string, name: string}|null
     */
    private static function leaf(CatalogProduct $product): ?array
    {
        $best = null;

        foreach ((array) ($product->payload['categories'] ?? []) as $category) {
            $path = is_array($category) ? (array) ($category['path'] ?? []) : [];

            if (count($path) < 2 || ($best !== null && count($path) <= $best['depth'])) {
                continue;
            }

            $best = ['id' => (string) ($category['id'] ?? ''), 'name' => (string) ($category['name'] ?? end($path)), 'depth' => count($path)];
        }

        return $best === null || $best['id'] === '' ? null : ['id' => $best['id'], 'name' => $best['name']];
    }

    /** @param array<string, mixed> $reasons */
    private function add(string $productId, string $relatedId, RelationKind $kind, string $source, int $score, array $reasons): void
    {
        $key = $productId.'|'.$relatedId.'|'.$kind->value;

        if (isset($this->rows[$key])) {
            // Found by several sources: keep the best score and every reason.
            $this->rows[$key]['score'] = max($this->rows[$key]['score'], $score) + 5;
            $this->rows[$key]['reasons']['also'][] = $source;

            return;
        }

        $this->rows[$key] = [
            'product_id' => $productId,
            'related_product_id' => $relatedId,
            'kind' => $kind->value,
            'source' => $source,
            'score' => $score,
            'reasons' => $reasons,
        ];
    }
}
