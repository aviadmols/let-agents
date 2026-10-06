<?php

namespace App\Modules\Widget\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Modules\ModuleRepository;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsPopularity;
use App\Modules\Analytics\Models\AnalyticsPrior;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Assistant\Contracts\SuggestsQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Enrichment\Models\EnrichmentRelationRules;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Leads\Contracts\OffersCallsToAction;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Models\WidgetCuration;
use App\Modules\Widget\Support\GuideRelevance;
use App\Modules\Widget\Support\OpeningHours;
use App\Modules\Widget\Support\ProductCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Everything the storefront widget shows on one page, built from checked facts only. No model
 * runs here: sentences are templates filled with approved values. Each section is one circle in
 * the widget.
 *
 *   product page   position (superlatives and level), specs, complement (relations: merchant
 *                  links, matching battery, care products...), family (other sizes),
 *                  alternatives, on_sale (alternatives on sale), good_for (jobs and guides)
 *   article        article_products (the products matched to the article)
 *
 * A product page also carries `compare`: its specs keyed for comparing with a product of the
 * same type the shopper viewed before, which the widget keeps in the browser.
 *
 * Prices and stock in the result are from the last catalog sync. The widget replaces them
 * with live values from the store before showing anything.
 */
final class BuildPageBank
{
    public const TYPES = ['product', 'content'];

    public const LOCALES = ['he', 'en'];

    /** Section candidate id => display model in the event spec. */
    public const MODELS = [
        'position' => 'position',
        'highlights' => 'explainer',
        'specs' => 'specs',
        'complement' => 'complement',
        'family' => 'family',
        'alternatives' => 'alternative',
        'on_sale' => 'on_sale',
        'good_for' => 'good_for',
        'guides' => 'guide_card',
        'article_products' => 'article_products',
        // Added by the widget itself from this visitor's own browsing, never from the page bank.
        'recent' => 'recent',
    ];

    /**
     * The panels a shop may switch off for itself, each with a flag of its own.
     *
     * "recent" is not here: it is the visitor's own browsing, added by the widget rather than
     * built here, and it already answers to shoppers.recent_products.
     */
    public const SWITCHABLE = [
        'position', 'highlights', 'specs', 'complement', 'family',
        'alternatives', 'on_sale', 'good_for', 'guides', 'article_products',
    ];

    /** More than this in the closed widget stops being an offer and becomes a menu. */
    private const MAX_SUGGESTED = 4;

    private const MAX_POSITIONS = 3;

    private const MAX_SPECS = 10;

    private const MAX_GUIDES = 3;

    private const MAX_FAMILY = 8;

    private const SPARE_PRODUCTS = 4;

    private const MAX_BROWSE_LINKS = 3;

    private const MAX_HIGHLIGHTS = 4;

    /** Questions the banner may turn through, most asked first. */
    private const MAX_ASKED_SHOWN = 3;

    private const MIN_LEVEL_SET = 3;

    /** A product in the best quarter of its set gets "among the highest". */
    private const LEVEL_SHARE = 0.25;

    /** @var array<string, mixed> vocabulary definitions by key, per build */
    private array $vocabularies = [];

    /** @var Collection<string, CatalogCategory>|null */
    private ?Collection $categories = null;

    private string $locale = 'he';

    private string $shopId = '';

    private bool $explain = false;

    /** @var array<string, array<string, array<string, mixed>>> candidate => item id ('' for the section) => why */
    private array $why = [];

    /** @var list<array{stage: string, sections: list<array<string, mixed>>}> the sections after each stage, when explaining */
    private array $trace = [];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  bool  $explain  also return, under "explain", why each section and item is there: for the
     *                         store team's page in the panel, never for the storefront
     * @return array<string, mixed>
     */
    public function handle(string $shopId, string $type, string $externalId, string $locale, bool $explain = false): array
    {
        $this->locale = in_array($locale, self::LOCALES, true) ? $locale : 'he';
        $this->shopId = $shopId;
        $this->vocabularies = [];
        $this->categories = null;
        $this->explain = $explain;
        $this->why = [];
        $this->trace = [];

        $bank = [
            'v' => 1,
            'shop' => $shopId,
            'locale' => $this->locale,
            'dir' => $this->locale === 'he' ? 'rtl' : 'ltr',
            'page' => ['type' => $type, 'id' => $externalId],
            'enabled' => false,
            'placement' => [
                'selector' => (string) Settings::get("widget.{$type}_selector", $shopId),
                'position' => (string) Settings::get("widget.{$type}_position", $shopId),
                'floating' => (bool) Settings::get('widget.floating_fallback', $shopId),
            ],
            'bank_version' => 1,
            // Circles, or the assistant that opens from one closed line. Same bank either way.
            'layout' => (string) Settings::get('widget.layout', $shopId),
            'teaser' => null,
            'sections' => [],
            'compare' => null,
            'labels' => $this->labels($type),
        ];

        if (! Features::enabled($type === 'product' ? 'widget.on_products' : 'widget.on_content', $shopId)) {
            return $bank;
        }

        $maxProducts = (int) Settings::get('widget.max_products', $shopId);
        // Sections carry spares, so a product dropped for never being clicked has a replacement.
        $pool = $maxProducts + self::SPARE_PRODUCTS;

        [$sections, $version, $compare] = $this->tenant->run($shopId, fn (): array => $type === 'product'
            ? $this->productSections($externalId, $pool)
            : [...$this->contentSections($externalId, $pool), null]);

        $bank['enabled'] = true;
        $this->traced('built', $sections);
        $sections = $this->allowed($shopId, $sections);
        $this->traced('allowed', $sections);
        // The order before anything was learned, and the share of shoppers shown it, so a held-out
        // visitor can be served the untouched arrangement from this same cached bank.
        $bank['baseline_order'] = array_values(array_map(fn (array $s): string => (string) $s['candidate'], $sections));
        $bank['holdout_percent'] = (int) Settings::get('analytics.holdout_percent', $shopId);
        $bank['sections'] = $this->tenant->run($shopId, function () use ($shopId, $type, $externalId, $sections, $maxProducts): array {
            $learned = $this->learned($shopId, $type, $externalId, $sections, $maxProducts);
            $this->traced('learned', $learned);

            return $this->curated($type, $externalId, $learned, $maxProducts);
        });
        $this->traced('curated', $bank['sections']);
        $bank['bank_version'] = max(1, $version);
        $bank['teaser'] = $bank['sections'] === [] ? null : $this->teaser($bank['sections'][0]);
        $bank['compare'] = $compare;
        // The question box (Assistant module), when the shop has it on for this kind of page, and
        // how many questions were already asked here: the assistant's line says so. On a guide the
        // question is about the guide — "sum this up for me" — not about a product.
        $bank['ask'] = Features::enabled($type === 'product' ? 'assistant.on_products' : 'assistant.on_content', $shopId);
        $bank['asked'] = $bank['ask'] ? $this->tenant->run($shopId, fn (): int => $this->asked($type, $externalId)) : 0;
        $bank['questions'] = $bank['ask'] ? $this->tenant->run($shopId, fn (): array => $this->askedQuestions($type, $externalId)) : [];
        // What this page is worth being asked, whether or not anybody has asked it yet. The
        // closed widget puts one of these in front of a shopper who has opened nothing, so it
        // has to be in the bank rather than fetched when the question box opens.
        $bank['suggested'] = $bank['ask'] ? $this->tenant->run($shopId, fn (): array => $this->suggestedQuestions($type, $externalId)) : [];
        $bank['contact'] = $this->contact($shopId);
        $bank['popularity'] = $type === 'product' ? $this->tenant->run($shopId, fn (): ?array => $this->popularity($shopId, $externalId)) : null;
        $bank['assurances'] = $this->tenant->run($shopId, fn (): array => $this->assurances($shopId, $type === 'product' ? $externalId : null));
        // The products this visitor viewed are their own, so the widget asks for them separately;
        // the bank only says whether to ask, and what the sign-up under them should say.
        $bank['recent'] = Features::enabled('shoppers.recent_products', $shopId);
        // Whether a shopper may leave a way to be told an answer the assistant did not have.
        $bank['callbacks'] = Features::enabled('shoppers.callbacks', $shopId);
        $bank['signup'] = $this->signUp($shopId);
        // Whether this shop has said what it wants from a reader. The flow itself is asked for
        // step by step, so nothing about it is cached with the page.
        $bank['lead'] = Features::enabled('leads.enabled', $shopId);
        // What this page offers a reader, in the version being tried on them.
        $bank['cta'] = app(OffersCallsToAction::class)->forPage($shopId, $type, $externalId, $this->locale);

        if ($this->explain) {
            $bank['explain'] = $this->why;
            $bank['trace'] = $this->trace;
        }

        return $bank;
    }

    /**
     * What the sections looked like after one stage, for the panel's picture of how a page is
     * put together: built from relations (with spares), allowed by the shop's switches, ordered
     * and trimmed by what shoppers did, and finally what the store team pinned or hid.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private function traced(string $stage, array $sections): void
    {
        if (! $this->explain) {
            return;
        }

        $this->trace[] = ['stage' => $stage, 'sections' => array_map(fn (array $section): array => [
            'candidate' => (string) $section['candidate'],
            'title' => (string) ($section['title'] ?? $section['candidate']),
            'items' => array_values(array_map(
                fn (array $item): array => ['id' => (string) ($item['id'] ?? ''), 'title' => (string) ($item['title'] ?? '')],
                array_filter((array) ($section['products'] ?? $section['guides'] ?? []), 'is_array'),
            )),
            'count' => count((array) ($section['products'] ?? $section['guides'] ?? $section['items'] ?? $section['lines'] ?? [])),
        ], $sections)];
    }

    /**
     * What the store team decided about this page, applied last: a hidden section or item is gone
     * whatever code or learning think; a pinned product is in its section, first, even when code
     * did not put it there; a pinned section comes before the others.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function curated(string $type, string $externalId, array $sections, int $maxProducts): array
    {
        $curations = WidgetCuration::query()->where('page_type', $type)->where('page_external_id', $externalId)->get();

        if ($curations->isEmpty()) {
            return $sections;
        }

        $hiddenSections = $curations->where('action', WidgetCuration::HIDE)->where('item_external_id', '')->pluck('candidate')->all();
        $pinnedSections = $curations->where('action', WidgetCuration::PIN)->where('item_external_id', '')->pluck('candidate')->all();
        $hidden = $curations->where('action', WidgetCuration::HIDE)->where('item_external_id', '!=', '')->groupBy('candidate')->map(fn (Collection $c): array => $c->pluck('item_external_id')->all());
        $pinned = $curations->where('action', WidgetCuration::PIN)->where('item_external_id', '!=', '')->sortBy('created_at')->groupBy('candidate')->map(fn (Collection $c): array => $c->pluck('item_external_id')->all());

        $sections = array_values(array_filter($sections, fn (array $s): bool => ! in_array($s['candidate'], $hiddenSections, true)));
        $byCandidate = array_column($sections, null, 'candidate');

        // A product pinned into a section code did not build: the section exists for it.
        foreach ($pinned->keys() as $candidate) {
            if (! isset($byCandidate[$candidate]) && in_array($candidate, WidgetCuration::PRODUCT_SECTIONS, true) && ! in_array($candidate, $hiddenSections, true)) {
                $sections[] = $this->section($candidate, ['products' => []]);
            }
        }

        foreach ($sections as $i => $section) {
            $candidate = (string) $section['candidate'];
            $itemsKey = isset($section['guides']) && ! isset($section['products']) ? 'guides' : 'products';

            if (! isset($section[$itemsKey]) && ! $pinned->has($candidate)) {
                continue;
            }

            $items = array_values(array_filter($section[$itemsKey] ?? [], fn (array $item): bool => ! in_array((string) $item['id'], $hidden->get($candidate, []), true)));

            if ($pinned->has($candidate) && $itemsKey === 'products') {
                $present = array_column($items, null, 'id');
                $missing = array_values(array_diff($pinned->get($candidate), array_keys($present)));
                $added = $missing === [] ? collect() : CatalogProduct::query()->active()->where('in_stock', true)->whereIn('external_id', $missing)->get()->keyBy('external_id');

                $first = [];
                foreach ($pinned->get($candidate) as $externalPinned) {
                    if (isset($present[$externalPinned])) {
                        $first[] = $present[$externalPinned];
                    } elseif ($added->has($externalPinned)) {
                        $first[] = $this->cards(collect([$added->get($externalPinned)]))[0];
                    }
                }

                $rest = array_values(array_filter($items, fn (array $item): bool => ! in_array((string) $item['id'], $pinned->get($candidate), true)));
                $items = array_slice([...$first, ...$rest], 0, max($maxProducts, count($first)));
                $this->note($candidate, '', ['pinned' => array_column($first, 'id')]);
            }

            $sections[$i][$itemsKey] = $items;

            if ($hidden->has($candidate)) {
                $this->note($candidate, '', ['hidden' => $hidden->get($candidate)]);
            }
        }

        $sections = array_values(array_filter($sections, fn (array $s): bool => ! isset($s['products']) || $s['products'] !== []));

        // Pinned sections first, in the order they were pinned; the rest keep their order.
        $order = array_keys($sections);
        usort($order, function (int $a, int $b) use ($sections, $pinnedSections): int {
            $rank = fn (int $i): int => ($p = array_search($sections[$i]['candidate'], $pinnedSections, true)) === false ? PHP_INT_MAX : $p;

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        return array_map(fn (int $i): array => $sections[$i], $order);
    }

    /**
     * Remembers why something is on the page, for the store team's page. Item '' is the section.
     *
     * @param  array<string, mixed>  $why
     */
    private function note(string $candidate, string $item, array $why): void
    {
        if ($this->explain) {
            $this->why[$candidate][$item] = array_merge($this->why[$candidate][$item] ?? [], $why);
        }
    }

    /** @return array{0: list<array<string, mixed>>, 1: int, 2: array<string, mixed>|null} */
    private function productSections(string $externalId, int $maxProducts): array
    {
        $product = CatalogProduct::query()->active()->where('external_id', $externalId)->first();

        if ($product === null) {
            return [[], 1, null];
        }

        $sections = [];
        $version = (int) $product->synced_at?->timestamp;

        $facts = EnrichmentFact::query()
            ->with('vocabulary')
            ->where('product_id', $product->id)
            ->where('status', FactStatus::Approved)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $definition = $facts->first(fn (EnrichmentFact $f): bool => $f->vocabulary !== null)?->vocabulary?->definition();
        $reading = EnrichmentCodeReading::query()->where('product_id', $product->id)->value('reading');

        // Why this model: superlatives, then "among the highest" levels, then a professional tag.
        $rankings = EnrichmentRanking::query()
            ->where('product_id', $product->id)
            ->where('rank', 1)
            ->orderBy('tied')
            ->orderByDesc('set_size')
            ->orderBy('metric')
            ->limit(self::MAX_POSITIONS)
            ->get();

        $lines = $rankings->map(function (EnrichmentRanking $r): array {
            // The banner shows a first place as a tile, so it needs the sentence without the
            // measurement and the size of the set on their own. A tie is no one's first place.
            $measure = $r->metric === 'price' ? null : $this->measure((float) $r->value, $r->unit);

            return array_filter([
                'text' => $this->positionText($r),
                'short' => $measure === null ? null : $this->positionText($r, false),
                'note' => $measure,
                'of' => $r->tied || $r->set_size < 3 ? null : $r->set_size,
                'metric' => $r->metric,
                // The widget hides a price superlative when the live price is not this one.
                'price' => $r->metric === 'price' ? (float) $r->value : null,
            ], fn ($v): bool => $v !== null);
        })->values()->all();

        if ($definition !== null) {
            $lines = [...$lines, ...$this->levelLines($product, $facts, $definition, $rankings->pluck('metric')->all())];

            if ($facts->contains(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Tag && $f->value_text === 'for_professionals')) {
                $lines[] = ['text' => __('widget::bank.position.professional', [], $this->locale), 'metric' => 'tag'];
            }
        }

        if ($lines !== []) {
            $version = max($version, (int) $rankings->max(fn (EnrichmentRanking $r): int => (int) $r->computed_at->timestamp));
        }

        // What a shopper should know, from the product's own text, in the order the writer chose.
        $highlights = $this->highlights($facts->where('kind', FactKind::Highlight)->sortBy('value_number')->take(self::MAX_HIGHLIGHTS)->values());
        // One panel for what to know: where the model stands among its kind, then the highlights.
        // The widget shows its first line as the quote above the circles.
        if ($lines !== [] || $highlights !== []) {
            $sections[] = $this->section('highlights', array_filter([
                'lines' => array_slice($lines, 0, self::MAX_POSITIONS + 2),
                'items' => $highlights,
            ]));
        }

        $specs = $this->specs($facts, $definition, (array) ($reading ?? []));
        if ($specs !== []) {
            $sections[] = $this->section('specs', ['specs' => $specs]);
        }

        // Products shown with this one, as ComputeProductRelations found them.
        $relations = EnrichmentProductRelation::query()
            ->with('related')
            ->where('product_id', $product->id)
            ->whereHas('related', fn ($q) => $q->whereNull('removed_at')->where('in_stock', true)->where('purchasable', true))
            ->orderByDesc('score')
            ->get()
            ->groupBy(fn (EnrichmentProductRelation $r): string => $r->kind->value);

        $version = max($version, (int) collect($relations->flatten())->max(fn (EnrichmentProductRelation $r): int => (int) $r->computed_at->timestamp));
        $ruleLabels = $this->ruleLabels();

        if ($this->explain) {
            foreach ($relations->flatten() as $relation) {
                $why = ['source' => $relation->source, 'score' => $relation->score, 'reasons' => $relation->reasons, 'label' => $this->relationReason($relation, $ruleLabels)];
                foreach ($relation->kind === RelationKind::Alternative ? ['alternatives', 'on_sale'] : [$relation->kind === RelationKind::Family ? 'family' : 'complement'] as $candidate) {
                    $this->note($candidate, (string) $relation->related->external_id, $why);
                }
            }
        }

        // Another size of this product is shown under other sizes, not again as a complement.
        $sizes = $relations->get(RelationKind::Family->value, collect())->pluck('related_product_id')->all();
        $complementKinds = self::kinds($relations->get(RelationKind::Complement->value, collect())
            ->reject(fn (EnrichmentProductRelation $r): bool => in_array($r->related_product_id, $sizes, true)));
        $complements = self::varied($complementKinds, $maxProducts);
        if ($complements->isNotEmpty()) {
            $sections[] = $this->section('complement', array_filter([
                'products' => $this->cards(
                    $complements->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related),
                    $complements->mapWithKeys(fn (EnrichmentProductRelation $r): array => [$r->related_product_id => $this->relationReason($r, $ruleLabels)]),
                ),
                'categories' => $this->browseLinks(array_map(fn (array $kind): array => array_map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related, $kind), $complementKinds)),
            ]));
        } elseif (($crossSells = $this->merchantCrossSells($product, $maxProducts))->isNotEmpty()) {
            // Relations not computed yet: the merchant's own cross-sells.
            $sections[] = $this->section('complement', ['products' => $this->cards($crossSells)]);
        }

        $family = $relations->get(RelationKind::Family->value, collect())->take(self::MAX_FAMILY);
        if ($family->isNotEmpty()) {
            $sections[] = $this->section('family', ['products' => $this->cards($family->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related))]);
        }

        $alternatives = $relations->get(RelationKind::Alternative->value, collect());
        if ($alternatives->isNotEmpty()) {
            $sections[] = $this->section('alternatives', array_filter([
                'products' => $this->cards($alternatives->take($maxProducts)->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related)),
                'categories' => $this->browseLinks([$alternatives->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related)->all()]),
            ]));

            // The widget checks "on sale" again with live prices.
            $onSale = $alternatives->filter(fn (EnrichmentProductRelation $r): bool => $r->related->on_sale)->take($maxProducts);
            if ($onSale->isNotEmpty()) {
                $sections[] = $this->section('on_sale', ['products' => $this->cards($onSale->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related)), 'require_sale' => true]);
            }
        }

        // Good for: approved jobs, with guides matched to the product or to those jobs.
        $uses = $facts->where('kind', FactKind::Use)->pluck('value_text')->unique()->values()->all();
        $guides = $this->guides($product, $uses);

        if ($uses !== []) {
            $labels = array_map(fn (string $use): string => $definition?->label('use', $use, $this->locale) ?? $use, $uses);
            $sections[] = $this->section('good_for', ['uses' => $labels, 'guides' => $guides], ['uses' => implode(' · ', array_slice($labels, 0, 2))]);
        } elseif ($guides !== []) {
            $sections[] = $this->section('guides', ['guides' => $guides]);
        }

        return [$sections, $version, $this->compare($facts, $definition, $specs)];
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function contentSections(string $externalId, int $maxProducts): array
    {
        $content = CatalogContent::query()->active()->where('external_id', $externalId)->orderBy('type')->first();

        if ($content === null) {
            return [[], 1];
        }

        // What the article itself says, for a site whose articles are the thing rather than a
        // route to a product. Code read these from the article's own lines.
        $sections = [];
        $takeaways = EnrichmentFact::query()
            ->where('content_id', $content->id)
            ->where('kind', FactKind::Highlight)
            ->where('status', FactStatus::Approved)
            ->orderBy('value_number')
            ->limit(self::MAX_HIGHLIGHTS)
            ->get();

        if ($takeaways->isNotEmpty()) {
            $sections[] = $this->section('highlights', [
                'items' => $takeaways->map(fn (EnrichmentFact $f): array => [
                    'id' => $f->key,
                    'key' => __('widget::bank.takeaway'),
                    'text' => (string) $f->value_text,
                ])->all(),
            ]);
        }

        $matches = EnrichmentContentProduct::query()
            ->with('product')
            ->where('content_id', $content->id)
            ->whereHas('product', fn ($q) => $q->whereNull('removed_at')->where('in_stock', true)->where('purchasable', true))
            ->orderBy('rank')
            ->limit($maxProducts)
            ->get();

        if ($matches->isEmpty()) {
            return [$sections, 1];
        }

        $products = $matches->map(fn (EnrichmentContentProduct $m): CatalogProduct => $m->product);
        $reasons = EnrichmentRanking::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('rank', 1)
            ->where('tied', false)
            ->orderByDesc('set_size')
            ->get()
            ->unique('product_id')
            ->mapWithKeys(fn (EnrichmentRanking $r): array => [$r->product_id => $this->positionText($r)]);

        $version = (int) $matches->max(fn (EnrichmentContentProduct $m): int => (int) $m->computed_at->timestamp);

        $sections[] = $this->section('article_products', ['products' => $this->cards($products, $reasons)]);

        return [$sections, $version];
    }

    /**
     * "Among the highest power of 12 cordless angle grinders": the product is in the best quarter
     * of products of its type and set, for a spec the vocabulary ranks. Skipped where a superlative
     * already says more.
     *
     * @param  Collection<int, EnrichmentFact>  $facts
     * @param  list<string>  $alreadyRanked
     * @return list<array{text: string, metric: string}>
     */
    private function levelLines(CatalogProduct $product, Collection $facts, mixed $definition, array $alreadyRanked): array
    {
        $type = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Type)?->value_text;

        if ($type === null) {
            return [];
        }

        $facets = ['type' => $type];
        foreach ($definition->setBy() as $key) {
            $choice = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Choice && $f->key === $key)?->value_text;

            if ($choice === null) {
                return [];
            }
            $facets[$key] = $choice;
        }

        // Products of the same type and set, in stock.
        $peers = EnrichmentFact::query()
            ->where('status', FactStatus::Approved)->where('kind', FactKind::Type)->where('value_text', $type)
            ->whereHas('product', fn ($q) => $q->whereNull('removed_at')->where('in_stock', true))
            ->pluck('product_id')->unique();

        foreach (array_slice($facets, 1, null, true) as $key => $value) {
            $peers = $peers->intersect(EnrichmentFact::query()->where('status', FactStatus::Approved)->where('kind', FactKind::Choice)
                ->where('key', $key)->where('value_text', $value)->pluck('product_id'));
        }

        if ($peers->count() < self::MIN_LEVEL_SET) {
            return [];
        }

        $set = [];
        foreach ($facets as $key => $value) {
            $set[] = $key === 'type' ? $definition->label('type', $value, $this->locale) : $definition->label('attribute', $key, $this->locale, $value);
        }

        $lines = [];

        foreach ($definition->attributes() as $attribute) {
            $direction = $attribute['rank'] ?? null;
            $own = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Spec && $f->key === $attribute['key']);

            if ($direction === null || $own === null || in_array($attribute['key'], $alreadyRanked, true)) {
                continue;
            }

            $values = EnrichmentFact::query()->where('status', FactStatus::Approved)->where('kind', FactKind::Spec)
                ->where('key', $attribute['key'])->whereIn('product_id', $peers->all())
                ->get(['product_id', 'value_number'])->unique('product_id')->pluck('value_number')->map(fn ($v): float => (float) $v);

            if ($values->count() < self::MIN_LEVEL_SET) {
                continue;
            }

            $mine = (float) $own->value_number;
            $better = $values->filter(fn (float $v): bool => $direction === 'max' ? $v > $mine : $v < $mine)->count();

            if ($better / $values->count() > self::LEVEL_SHARE) {
                continue;
            }

            $lines[] = [
                'text' => __('widget::bank.position.level_'.$direction, [
                    'metric' => $definition->label('attribute', $attribute['key'], $this->locale),
                    'size' => $values->count(),
                    'set' => implode(' · ', $set),
                ], $this->locale).' · '.$this->measure($mine, $own->unit),
                'metric' => $attribute['key'],
            ];
        }

        return $lines;
    }

    /**
     * What worked, applied. Scores come from the Analytics module's nightly run.
     *
     * - Products inside a section: the ones clicked, added or bought from it on this page first.
     *   Once the section was opened enough times here, the products it showed and nobody clicked
     *   are dropped and the spares behind them move up. A section left with nothing is dropped.
     *   Family (other sizes) is reordered, never dropped.
     * - Sections: in order of their score on this page, or across the shop while the page has none.
     *   A section nobody has seen yet gets the best known score, so it gets seen.
     *
     * Without scores of its own, a shop borrows how the panels tend to do across its trade —
     * rates from other shops, with nothing of theirs in them. Without that either, the built
     * order stands.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    /**
     * The order the shops of this trade would put these panels in.
     *
     * A shop opened this morning has watched nobody, and the order its panels happen to be
     * built in is not an opinion about anything. Its trade has been watched for months.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function asTradeWould(string $shopId, array $sections): array
    {
        $shop = $this->tenant->runUnscoped(fn () => Shop::query()->find($shopId));
        $priors = AnalyticsPrior::forVertical($shop?->vertical?->value);

        if ($priors === []) {
            return $sections;
        }

        // A panel the trade has never been able to judge keeps a middling position rather
        // than being pushed to the end for having no evidence either way.
        $middle = array_sum($priors) / count($priors);

        usort($sections, fn (array $a, array $b): int => ($priors[$b['candidate']] ?? $middle) <=> ($priors[$a['candidate']] ?? $middle));

        foreach ($sections as $section) {
            $this->note((string) $section['candidate'], '', ['trade' => [
                'vertical' => $shop?->vertical?->value,
                'score' => $priors[$section['candidate']] ?? null,
            ]]);
        }

        return $sections;
    }

    private function learned(string $shopId, string $type, string $externalId, array $sections, int $maxProducts): array
    {
        $scores = $sections === [] ? collect() : AnalyticsScore::query()
            ->where(fn ($q) => $q->where('scope', AnalyticsScore::SCOPE_MODULE)
                ->orWhere(fn ($q) => $q->where('page_type', $type)->where('page_external_id', $externalId)))
            ->get();

        $module = $scores->where('scope', AnalyticsScore::SCOPE_MODULE)->keyBy('candidate');
        $page = $scores->where('scope', AnalyticsScore::SCOPE_PAGE)->keyBy('candidate');
        $related = $scores->where('scope', AnalyticsScore::SCOPE_RELATED)->groupBy('candidate')
            ->map(fn (Collection $rows): array => $rows->mapWithKeys(fn (AnalyticsScore $s): array => [$s->related_external_id => (float) $s->score])->all());
        $dropAfter = (int) Settings::get('analytics.drop_related_after_opens', $shopId);

        foreach ($sections as $i => $section) {
            if (! isset($section['products'])) {
                continue;
            }

            $candidate = (string) $section['candidate'];
            $worked = $related->get($candidate, []);
            $products = $section['products'];

            if ($candidate !== 'family' && (int) ($page->get($candidate)?->opens ?? 0) >= $dropAfter) {
                $shown = array_column(array_slice($products, 0, $maxProducts), 'id');
                $products = array_filter($products, fn (array $p): bool => isset($worked[(string) $p['id']]) || ! in_array($p['id'], $shown, true));
            }

            $products = self::workedFirst($products, $worked);
            $sections[$i]['products'] = $candidate === 'family' ? $products : array_slice($products, 0, $maxProducts);
        }

        // Guides: the ones read from this section first.
        foreach ($sections as $i => $section) {
            if (! empty($section['guides'])) {
                $sections[$i]['guides'] = self::workedFirst($section['guides'], $related->get((string) $section['candidate'], []));
            }
        }

        $sections = array_values(array_filter($sections, fn (array $s): bool => ! isset($s['products']) || $s['products'] !== []));

        if ($scores->isEmpty()) {
            return $this->asTradeWould($shopId, $sections);
        }

        $known = $module->pluck('score')->map(fn ($s): float => (float) $s)->filter(fn (float $s): bool => $s > 0);
        $explore = $known->isEmpty() ? 0.0 : (float) $known->max();

        $rank = fn (array $section): float => (float) ($page->get($section['candidate'])?->score
            ?? $module->get($section['candidate'])?->score
            ?? $explore);

        foreach ($sections as $section) {
            $pageScore = $page->get($section['candidate']);
            $this->note((string) $section['candidate'], '', [
                'learned' => [
                    'page_score' => $pageScore?->score,
                    'page_opens' => $pageScore?->opens,
                    'page_exposures' => $pageScore?->exposures,
                    'module_score' => $module->get($section['candidate'])?->score,
                    'explore' => $pageScore === null && ! $module->has($section['candidate']) ? $explore : null,
                    'rank' => $rank($section),
                ],
            ]);
        }

        $order = array_keys($sections);
        usort($order, fn (int $a, int $b): int => [$rank($sections[$b]), $a] <=> [$rank($sections[$a]), $b]);

        return array_map(fn (int $i): array => $sections[$i], $order);
    }

    /**
     * Highlights for the widget. One whose quote the store repeats on many products ("a deviation
     * of 2-3 mm is possible") says little about this product: it goes last and is marked common,
     * so the widget does not make it the key sentence.
     *
     * @param  Collection<int, EnrichmentFact>  $facts  in the writer's order
     * @return list<array{key: string, text: string, common?: bool}>
     */
    private function highlights(Collection $facts): array
    {
        if ($facts->isEmpty()) {
            return [];
        }

        $threshold = (int) Settings::get('widget.common_highlight_products', $this->shopId);
        $shared = EnrichmentFact::query()
            ->where('kind', FactKind::Highlight)
            ->where('status', FactStatus::Approved)
            ->whereIn('quote', $facts->pluck('quote')->filter()->all())
            ->selectRaw('quote, count(distinct product_id) as products')
            ->groupBy('quote')
            ->pluck('products', 'quote');

        $items = $facts->map(function (EnrichmentFact $f) use ($shared, $threshold): array {
            $this->note('highlights', (string) $f->key, ['quote' => $f->quote, 'model' => $f->model, 'review' => $f->review_model, 'products_with_this_quote' => (int) ($shared[$f->quote] ?? 0)]);

            return array_filter([
                'key' => $f->key,
                'text' => (string) $f->value_text,
                'common' => (int) ($shared[$f->quote] ?? 0) >= $threshold ?: null,
            ], fn ($v): bool => $v !== null);
        })->all();

        usort($items, fn (array $a, array $b): int => isset($a['common']) <=> isset($b['common']));

        return $items;
    }

    /**
     * Items by their related score, highest first; items without one keep their built order after.
     *
     * @param  array<int, array<string, mixed>>  $items  each with an id
     * @param  array<string, float>  $worked  item id => related score
     * @return list<array<string, mixed>>
     */
    private static function workedFirst(array $items, array $worked): array
    {
        $items = array_values($items);
        $positions = array_keys($items);
        usort($positions, fn (int $a, int $b): int => [$worked[(string) $items[$b]['id']] ?? 0.0, $a] <=> [$worked[(string) $items[$a]['id']] ?? 0.0, $b]);

        return array_map(fn (int $p): array => $items[$p], $positions);
    }

    /**
     * @param  Collection<int, CatalogProduct>  $products
     * @param  Collection<string, string>|null  $reasons  product id => sentence
     * @return list<array<string, mixed>>
     */
    private function cards(Collection $products, ?Collection $reasons = null): array
    {
        return ProductCard::many($products, $reasons);
    }

    /** @return Collection<int, CatalogProduct> */
    private function merchantCrossSells(CatalogProduct $product, int $limit): Collection
    {
        $targets = array_values(array_unique(array_column(array_filter($product->merchantRelations(), fn (array $r): bool => $r['type'] === 'cross_sell'), 'target')));

        if ($targets === []) {
            return collect();
        }

        return CatalogProduct::query()->active()->where('in_stock', true)->where('purchasable', true)
            ->whereIn('external_id', $targets)->get()
            ->sortBy(fn (CatalogProduct $p): int => (int) array_search($p->external_id, $targets, true))
            ->take($limit)
            ->values();
    }

    /** @param array<string, array<string, string>> $ruleLabels */
    private function relationReason(EnrichmentProductRelation $relation, array $ruleLabels): ?string
    {
        $reasons = $relation->reasons;
        // A merchant link a rule also found says what the rule says.
        $label = collect([$relation->source, ...($reasons['also'] ?? [])])
            ->map(fn (string $source): ?string => $ruleLabels[$source][$this->locale] ?? null)
            ->first(fn (?string $label): bool => $label !== null);

        // What shoppers really did. The number of orders stays here: a shopper is told that
        // people buy the two together, not how many of them there were.
        if ($label === null && isset($reasons['bought_together'])) {
            return __('widget::bank.reasons.bought_together', [], $this->locale);
        }

        // The store's own habit: products of this category are usually linked to that one.
        if ($label === null && $relation->source === 'category_affinity' && isset($reasons['affinity'][1])) {
            return __('widget::bank.reasons.category_affinity', ['category' => $reasons['affinity'][1]], $this->locale);
        }

        if ($label === null) {
            return null;
        }

        $details = array_filter([
            $reasons['brand'] ?? null,
            isset($reasons['voltage_v']) ? $this->measure((float) $reasons['voltage_v'], 'V') : null,
        ]);

        return implode(' · ', [$label, ...$details]);
    }

    /**
     * Related products by kind: the rule that found the product; a merchant link no rule found takes
     * the rule of a product in its deepest store category, or that category. Kinds come in the
     * order of their best score.
     *
     * @param  Collection<int, EnrichmentProductRelation>  $relations  best score first
     * @return list<list<EnrichmentProductRelation>>
     */
    private static function kinds(Collection $relations): array
    {
        $category = fn (EnrichmentProductRelation $r): string => (string) (collect((array) ($r->related->payload['categories'] ?? []))
            ->sortByDesc(fn ($c): int => count((array) ($c['path'] ?? [])))
            ->first()['id'] ?? '');
        $rule = fn (EnrichmentProductRelation $r): ?string => $r->reasons['rule'] ?? collect((array) ($r->reasons['also'] ?? []))
            ->first(fn ($source): bool => ! str_starts_with((string) $source, 'merchant'));

        $ruleOfCategory = [];
        foreach ($relations as $relation) {
            if (($key = $rule($relation)) !== null) {
                $ruleOfCategory[$category($relation)] ??= $key;
            }
        }

        return $relations
            ->groupBy(fn (EnrichmentProductRelation $r): string => $rule($r) ?? $ruleOfCategory[$category($r)] ?? 'category:'.$category($r))
            ->map(fn (Collection $group): array => $group->values()->all())
            ->values()
            ->all();
    }

    /**
     * One of each kind in turn: a shelf shows a support and a wall fixing before a second support,
     * a lamp a battery and a charger.
     *
     * @param  list<list<EnrichmentProductRelation>>  $groups  from kinds()
     * @return Collection<int, EnrichmentProductRelation>
     */
    private static function varied(array $groups, int $limit): Collection
    {
        $varied = [];
        for ($round = 0; count($varied) < $limit && $groups !== []; $round++) {
            foreach ($groups as $g => $group) {
                if (! isset($group[$round])) {
                    unset($groups[$g]);

                    continue;
                }
                if (count($varied) < $limit) {
                    $varied[] = $group[$round];
                }
            }
        }

        return collect($varied);
    }

    /**
     * A store category to browse for each kind of related product: the category most products of
     * that kind share, the deepest and then the smallest when several tie ("shelf supports" before
     * "shelving" when every support is in both), below the top level (a top-level category such
     * as "sale" says nothing about the kind), with a page, and holding more than a section shows.
     *
     * @param  list<list<CatalogProduct>>  $kinds
     * @return list<array{id: string, title: string, url: string}>
     */
    private function browseLinks(array $kinds): array
    {
        $links = [];

        foreach ($kinds as $products) {
            $counts = [];
            foreach ($products as $product) {
                foreach ((array) ($product->payload['categories'] ?? []) as $category) {
                    $id = is_array($category) ? (string) ($category['id'] ?? '') : '';
                    if ($id !== '') {
                        $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                }
            }

            $best = collect(array_keys($counts))
                ->map(fn ($id): ?CatalogCategory => $this->categories()->get((string) $id))
                ->filter(fn (?CatalogCategory $c): bool => $c !== null && $c->depth > 0 && (string) $c->url !== '' && $c->product_count > count($products))
                ->sort(fn (CatalogCategory $a, CatalogCategory $b): int => [$counts[$b->external_id], $b->depth, $a->product_count] <=> [$counts[$a->external_id], $a->depth, $b->product_count])
                ->first();

            if ($best !== null && ! isset($links[$best->external_id])) {
                $links[$best->external_id] = ['id' => $best->external_id, 'title' => $best->name, 'url' => (string) $best->url];
            }
        }

        return array_slice(array_values($links), 0, self::MAX_BROWSE_LINKS);
    }

    /** @return Collection<string, CatalogCategory> active categories by external id, per build */
    private function categories(): Collection
    {
        return $this->categories ??= CatalogCategory::query()->whereNull('removed_at')->get()->keyBy('external_id');
    }

    /** @return array<string, string|null> category external id => parent external id */
    private function categoryParents(): array
    {
        return $this->categories()->map(fn (CatalogCategory $c): ?string => $c->parent_external_id)->all();
    }

    /** @return array<string, array<string, string>> rule key => labels */
    private function ruleLabels(): array
    {
        $rules = EnrichmentRelationRules::query()->where('active', true)->orderByDesc('version')->value('definition');

        return collect((array) ($rules['rules'] ?? []))->mapWithKeys(fn (array $rule): array => [$rule['key'] => (array) ($rule['label'] ?? [])])->all();
    }

    /**
     * Guides for the product: articles matched to it and articles a checker approved for the same
     * jobs, kept and ranked by GuideRelevance.
     *
     * @param  list<string>  $uses
     * @return list<array<string, mixed>>
     */
    private function guides(CatalogProduct $product, array $uses): array
    {
        $matched = EnrichmentContentProduct::query()
            ->where('product_id', $product->id)
            ->orderByDesc('score')
            ->orderBy('rank')
            ->pluck('content_id')
            ->all();

        $forJobs = $uses === [] ? [] : EnrichmentFact::query()
            ->whereNotNull('content_id')
            ->where('kind', FactKind::Use)
            ->where('status', FactStatus::Approved)
            ->whereIn('value_text', $uses)
            ->orderBy('created_at')
            ->pluck('content_id')
            ->all();

        $ids = array_values(array_unique([...$matched, ...$forJobs]));
        if ($ids === []) {
            return [];
        }

        $articles = CatalogContent::query()->whereIn('id', $ids)->whereNull('removed_at')->get()->keyBy('id');
        $facts = EnrichmentFact::query()
            ->whereIn('content_id', $articles->keys())
            ->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::ContentKind, FactKind::ShopperValue, FactKind::Category, FactKind::Use])
            ->get(['content_id', 'kind', 'value_text'])
            ->groupBy('content_id');

        $relevance = new GuideRelevance($this->categoryParents());
        $productCategories = array_values(array_filter(array_map(fn ($c): string => is_array($c) ? (string) ($c['id'] ?? '') : '', (array) ($product->payload['categories'] ?? []))));

        $scored = [];
        foreach ($ids as $order => $id) {
            if (! $articles->has($id)) {
                continue;
            }

            $of = fn (FactKind $kind): array => $facts->get($id, collect())->where('kind', $kind)->pluck('value_text')->all();
            $article = [
                'kind' => $of(FactKind::ContentKind)[0] ?? null,
                'value' => $of(FactKind::ShopperValue)[0] ?? null,
                'categories' => $of(FactKind::Category),
                'uses' => $of(FactKind::Use),
                'matched' => in_array($id, $matched, true),
            ];
            $score = $relevance->score($productCategories, $uses, $article);
            $this->note('guide', (string) $articles->get($id)->external_id, $article + [
                'score' => $score,
                'topic' => $relevance->topic($productCategories, $article['categories']),
                'shared_uses' => array_values(array_intersect($article['uses'], $uses)),
            ]);

            if ($score !== null) {
                $scored[] = ['article' => $articles->get($id), 'score' => $score, 'order' => $order];
            }
        }

        usort($scored, fn (array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return collect($scored)->pluck('article')->take(self::MAX_GUIDES)->map(fn (CatalogContent $c): array => [
            'id' => $c->external_id,
            'title' => $c->title,
            'url' => $c->url,
            'image' => $c->image_url,
        ])->values()->all();
    }

    /**
     * Specs from approved facts, then what code read that a shopper should know: the brand, what
     * to choose on the product page, the price unit, the pack size.
     *
     * @param  Collection<int, EnrichmentFact>  $facts
     * @param  array<string, mixed>  $reading
     * @return list<array{label: string, value: string, key?: string}>
     */
    private function specs(Collection $facts, mixed $definition, array $reading): array
    {
        $rows = [];
        $byKey = $facts->whereIn('kind', [FactKind::Type, FactKind::Spec, FactKind::Choice, FactKind::Flag])
            ->keyBy(fn (EnrichmentFact $f): string => $f->kind->value.'|'.$f->key);

        if ($definition !== null && $byKey->isNotEmpty()) {
            if (($type = $byKey->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Type)) !== null) {
                $rows[] = ['key' => 'type', 'label' => __('widget::bank.type', [], $this->locale), 'value' => $definition->label('type', (string) $type->value_text, $this->locale)];
            }

            // What it is first (choices), then numbers, then yes/no features; each in vocabulary order.
            $order = [FactKind::Choice->value => 0, FactKind::Spec->value => 1, FactKind::Flag->value => 2];
            $attributes = $definition->attributes();
            usort($attributes, function (array $a, array $b) use ($byKey, $order): int {
                $kind = fn (array $attribute): int => $order[$byKey->first(fn (EnrichmentFact $f): bool => $f->key === $attribute['key'] && $f->kind !== FactKind::Type)?->kind->value] ?? 3;

                return $kind($a) <=> $kind($b);
            });

            foreach ($attributes as $attribute) {
                $fact = $byKey->first(fn (EnrichmentFact $f): bool => $f->key === $attribute['key'] && $f->kind !== FactKind::Type);

                if ($fact === null) {
                    continue;
                }

                $value = match ($fact->kind) {
                    FactKind::Spec => $this->measure((float) $fact->value_number, $fact->unit),
                    FactKind::Choice => $definition->label('attribute', $fact->key, $this->locale, (string) $fact->value_text),
                    default => __('widget::bank.yes', [], $this->locale),
                };

                $rows[] = ['key' => $attribute['key'], 'label' => $definition->label('attribute', $fact->key, $this->locale), 'value' => $value];
            }
        }

        if (isset($reading['brand']['brand'])) {
            $rows[] = ['key' => 'brand', 'label' => __('widget::bank.brand', [], $this->locale), 'value' => (string) $reading['brand']['brand']];
        }

        foreach ((array) ($reading['choices'] ?? []) as $choice) {
            $values = (array) $choice['values'];
            $rows[] = [
                'label' => __('widget::bank.to_choose', ['name' => $choice['name']], $this->locale),
                'value' => count($values) > 4 ? reset($values).' – '.end($values) : implode(', ', $values),
            ];
        }

        if (isset($reading['pack_count'])) {
            $rows[] = ['key' => 'pack_count', 'label' => __('widget::bank.pack', [], $this->locale), 'value' => (string) $reading['pack_count']];
        }

        if (isset($reading['price_unit'])) {
            $rows[] = ['label' => __('widget::bank.price_unit', [], $this->locale), 'value' => (string) $reading['price_unit']];
        }

        return array_slice($rows, 0, self::MAX_SPECS);
    }

    /**
     * What the widget remembers to compare this product with another of the same type later.
     *
     * @param  Collection<int, EnrichmentFact>  $facts
     * @param  list<array{label: string, value: string, key?: string}>  $specs
     * @return array<string, mixed>|null
     */
    private function compare(Collection $facts, mixed $definition, array $specs): ?array
    {
        $type = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Type);

        if ($definition === null || $type === null) {
            return null;
        }

        $rows = [];
        foreach ($specs as $row) {
            if (isset($row['key']) && $row['key'] !== 'type') {
                $rows[] = [$row['key'], $row['label'], $row['value']];
            }
        }

        return $rows === [] ? null : [
            'key' => $definition->key().'|'.$type->value_text,
            'type' => $definition->label('type', (string) $type->value_text, $this->locale),
            'rows' => $rows,
        ];
    }

    private function positionText(EnrichmentRanking $ranking, bool $withMeasure = true): string
    {
        $vocabularyKey = explode('|', $ranking->set_key)[0];
        $definition = $this->vocabularies[$vocabularyKey] ??= EnrichmentVocabulary::query()
            ->where('key', $vocabularyKey)->where('active', true)->first()?->definition();

        $metric = $ranking->metric === 'price'
            ? __('widget::bank.position.price', [], $this->locale)
            : ($definition?->label('attribute', $ranking->metric, $this->locale) ?? $ranking->metric);

        $set = [];
        foreach ($ranking->set_facets as $key => $value) {
            $set[] = $key === 'type'
                ? ($definition?->label('type', (string) $value, $this->locale) ?? $value)
                : ($definition?->label('attribute', (string) $key, $this->locale, (string) $value) ?? $value);
        }

        $template = ($ranking->tied ? 'tied_' : 'best_').($ranking->direction === 'min' ? 'min' : 'max');
        $text = __("widget::bank.position.{$template}", [
            'metric' => $metric,
            'size' => $ranking->set_size,
            'set' => implode(' · ', $set),
        ], $this->locale);

        return $ranking->metric === 'price' || ! $withMeasure
            ? $text
            : $text.' · '.$this->measure((float) $ranking->value, $ranking->unit);
    }

    private function measure(float $value, ?string $unit): string
    {
        // The unit a shopper reads: 1,200 mm is 1.2 m, 0.4 L is 400 ml, 0.25 kg is 250 g.
        if ($unit === 'mm' && $value >= 1000) {
            [$value, $unit] = [$value / 1000, 'm'];
        } elseif ($unit === 'L' && $value > 0 && $value < 1) {
            [$value, $unit] = [$value * 1000, 'ml'];
        } elseif ($unit === 'kg' && $value > 0 && $value < 1) {
            [$value, $unit] = [$value * 1000, 'g'];
        }

        $number = rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');

        if ($unit === null || $unit === '') {
            return $number;
        }

        $label = __("widget::bank.units.{$unit}", [], $this->locale);

        return $number.' '.(str_starts_with($label, 'widget::') ? $unit : $label);
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, string>  $chipReplace
     * @return array<string, mixed>
     */
    private function section(string $candidate, array $content, array $chipReplace = []): array
    {
        return [
            'candidate' => $candidate,
            'model' => self::MODELS[$candidate],
            'title' => __('widget::bank.titles.'.$candidate, [], $this->locale),
            'chip' => __('widget::bank.chips.'.$candidate, $chipReplace, $this->locale),
        ] + $content;
    }

    /**
     * The panels this shop lets a shopper see.
     *
     * A shop turns a panel off because it does not want it on its pages, so it goes before
     * anything else looks at it: what is off is never learned from, never curated, and never
     * becomes the teaser. A panel with no flag of its own is always shown.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function allowed(string $shopId, array $sections): array
    {
        return array_values(array_filter($sections, function (array $section) use ($shopId): bool {
            $candidate = (string) $section['candidate'];

            return ! in_array($candidate, self::SWITCHABLE, true)
                || Features::enabled('widget.show_'.$candidate, $shopId);
        }));
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array{candidate: string, model: string, text: string}
     */
    private function teaser(array $section): array
    {
        $text = match ($section['candidate']) {
            'position' => $section['lines'][0]['text'],
            'highlights' => isset($section['lines'][0]) ? $section['lines'][0]['text'] : $section['items'][0]['key'].' '.$section['items'][0]['text'],
            'article_products' => trans_choice('widget::bank.teasers.article_products', count($section['products']), ['count' => count($section['products'])], $this->locale),
            default => (string) $section['chip'],
        };

        return ['candidate' => $section['candidate'], 'model' => $section['model'], 'text' => $text];
    }

    /**
     * Talking to the store on WhatsApp: the sentence the shop wrote, its hours, and the message the
     * shopper sends, with the product filled in by the widget. Whether the shop is online now is
     * decided in the browser, so a cached page never says "online" after closing time.
     *
     * @return array<string, mixed>|null
     */
    private function contact(string $shopId): ?array
    {
        $number = preg_replace('/\D/', '', (string) Settings::get('widget.whatsapp_number', $shopId));

        if (! Features::enabled('widget.whatsapp', $shopId) || mb_strlen((string) $number) < 8) {
            return null;
        }

        $text = fn (string $name): string => trim((string) Settings::get("widget.whatsapp_{$name}", $shopId))
            ?: (string) __("widget::bank.contact.{$name}", [], $this->locale);

        return [
            'number' => $number,
            'title' => $text('title'),
            'button' => $text('button'),
            // :product and :url are filled in by the widget with the page it is on.
            'message' => $text('message'),
            'offline_note' => $text('offline_note'),
            'hide_when_offline' => Settings::get('widget.whatsapp_when_offline', $shopId) === 'hide',
            'timezone' => (string) Settings::get('widget.whatsapp_timezone', $shopId),
            // One entry per day, Sunday first. "09:00-18:00", or empty on a day the shop is closed.
            'hours' => OpeningHours::fromDays(array_map(
                fn (string $day): string => (string) Settings::get("widget.hours_{$day}", $shopId),
                OpeningHours::DAYS,
            ))->toList(),
            'online_label' => (string) __('widget::bank.contact.online', [], $this->locale),
            'offline_label' => (string) __('widget::bank.contact.offline', [], $this->locale),
        ];
    }

    /** How many questions shoppers asked about this product and got an answer to. */
    private function asked(string $type, string $externalId): int
    {
        return $this->answersHere($type, $externalId)?->count() ?? 0;
    }

    /**
     * Questions shoppers really asked on this page and got an answer to. An answer is only shown
     * after the small model agreed the question was about this page and agreed again that the
     * answer was, so every question here is one that fitted. Most asked first; the banner offers
     * them instead of an empty invitation, and asking one again costs nothing.
     *
     * @return list<string>
     */
    /**
     * The questions the closed widget may offer, asked or not.
     *
     * @return list<string>
     */
    private function suggestedQuestions(string $type, string $externalId): array
    {
        $page = $type === 'content'
            ? CatalogContent::query()->active()->where('external_id', $externalId)->first()
            : CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->first();

        if ($page === null) {
            return [];
        }

        return app(SuggestsQuestions::class)->for($page->id, $type === 'content', $this->locale, self::MAX_SUGGESTED);
    }

    private function askedQuestions(string $type, string $externalId): array
    {
        return $this->answersHere($type, $externalId)
            ?->where('outcome', AssistantAnswer::ANSWERED)
            ->orderByDesc('asked_count')->orderByDesc('last_asked_at')
            ->limit(self::MAX_ASKED_SHOWN)
            ->pluck('question')
            ->all() ?? [];
    }

    /** @return Builder<AssistantAnswer>|null null when the module is off or the page is unknown */
    private function answersHere(string $type, string $externalId): ?Builder
    {
        if (app(ModuleRepository::class)->get('Assistant')?->enabled !== true) {
            return null;
        }

        $page = $type === 'product'
            ? CatalogProduct::query()->where('external_id', $externalId)->first()
            : CatalogContent::query()->where('external_id', $externalId)->first();

        return $page === null ? null : AssistantAnswer::query()
            ->where($type === 'product' ? 'product_id' : 'content_id', $page->id)
            ->where('status', AssistantAnswer::SHOWN)
            ->whereNotNull('answer');
    }

    /**
     * How wanted this product is, from the nightly counts (ComputePopularity): how many times it
     * was added to the cart and ordered lately, and a "popular" mark when it is among the shop's
     * most wanted. A count under widget.popularity_min_count is kept from shoppers; two adds prove
     * nothing. The team's page still sees the numbers.
     *
     * @return array<string, mixed>|null
     */
    private function popularity(string $shopId, string $externalId): ?array
    {
        if (! Features::enabled('widget.popularity', $shopId)) {
            return null;
        }

        $row = AnalyticsPopularity::query()->where('product_external_id', $externalId)->first();

        if ($row === null) {
            return null;
        }

        $min = (int) Settings::get('widget.popularity_min_count', $shopId);
        $this->note('popularity', '', [
            'adds' => $row->adds, 'orders' => $row->orders, 'units' => $row->units, 'score' => $row->score,
            'rank' => $row->rank, 'popular' => $row->popular, 'days' => $row->window_days, 'min_count' => $min,
        ]);

        $parts = [];
        if ($row->adds >= $min) {
            $parts['adds'] = trans_choice('widget::bank.popularity.adds', $row->adds, ['count' => $row->adds], $this->locale);
        }
        if ($row->orders >= $min) {
            $parts['orders'] = trans_choice('widget::bank.popularity.orders', $row->orders, ['count' => $row->orders], $this->locale);
        }

        $text = null;
        if ($parts !== []) {
            $what = count($parts) === 2 ? (string) __('widget::bank.popularity.both', $parts, $this->locale) : (string) reset($parts);
            $text = (string) __('widget::bank.popularity.sentence', ['what' => $what, 'days' => $row->window_days], $this->locale);
            $text = mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
        }

        if ($text === null && ! $row->popular) {
            return null;
        }

        return [
            'popular' => $row->popular,
            'adds' => $row->adds,
            'orders' => $row->orders,
            'days' => $row->window_days,
            'text' => $text,
            'badge' => $row->popular ? (string) __('widget::bank.popularity.badge', [], $this->locale) : null,
        ];
    }

    /**
     * The invitation to leave a phone or an email, under the products the visitor viewed. The shop
     * can write its own sentence and its own consent wording; both fall back to the built-in text.
     *
     * @return array<string, string>|null
     */
    private function signUp(string $shopId): ?array
    {
        if (! Features::enabled('shoppers.signup', $shopId)) {
            return null;
        }

        $text = fn (string $name): string => trim((string) Settings::get("shoppers.signup_{$name}", $shopId))
            ?: (string) __("widget::bank.signup.{$name}", [], $this->locale);

        return array_filter([
            'title' => $text('title'),
            'note' => trim((string) Settings::get('shoppers.signup_note', $shopId)) ?: null,
            'consent' => $text('consent'),
            'placeholder' => (string) __('widget::bank.signup.placeholder', [], $this->locale),
            'button' => (string) __('widget::bank.signup.button', [], $this->locale),
        ], fn ($v): bool => $v !== null);
    }

    /** @return array<string, string> */
    /**
     * The widget's own words. On a guide, the sentences that name a product are replaced by the
     * ones that name the guide, so the assistant says "ask me about this guide" where it should.
     */
    private function labels(string $type): array
    {
        $labels = (array) __('widget::bank.ui', [], $this->locale);

        if ($type === 'product') {
            return $labels;
        }

        foreach ($labels as $key => $value) {
            if (str_ends_with($key, '_article')) {
                $labels[substr($key, 0, -8)] = $value;
            }
        }

        return $labels;
    }

    /** What the shop promises, in the order a shopper cares about it, then what a product is. */
    private const PROMISE_ORDER = ['returns', 'free_shipping', 'warranty', 'delivery', 'payments', 'handmade', 'pure_material', 'made_in'];

    /**
     * What helps a shopper decide beyond the product itself: the shop's own promises, read from
     * its pages, and what this product is made of, read from its text. Two lines at most — the
     * strongest promise leads and the rest follow it quietly — and every one of them was quoted
     * from the store's own words before it was approved.
     *
     * @return list<array{scope: string, text: string, note: string}>
     */
    private function assurances(string $shopId, ?string $productExternalId): array
    {
        if (! Features::enabled('widget.promises', $shopId)) {
            return [];
        }

        $product = $productExternalId === null ? null
            : CatalogProduct::query()->active()->where('external_id', $productExternalId)->first();

        $facts = EnrichmentFact::query()
            ->where('kind', FactKind::Promise)
            ->where('status', FactStatus::Approved)
            ->where(fn (Builder $q) => $q->whereNull('product_id')->when($product, fn (Builder $p) => $p->orWhere('product_id', $product->id)))
            ->get()
            ->unique(fn (EnrichmentFact $f): string => ($f->product_id === null ? 'shop' : 'product').'|'.$f->key)
            ->sortBy(fn (EnrichmentFact $f): int => (int) array_search($f->key, self::PROMISE_ORDER, true));

        $assurances = [];

        foreach (['shop', 'product'] as $scope) {
            $lines = $facts
                ->filter(fn (EnrichmentFact $f): bool => ($f->product_id === null ? 'shop' : 'product') === $scope)
                ->map(fn (EnrichmentFact $f): string => $this->promiseText($f))
                ->filter()
                ->values();

            if ($lines->isEmpty()) {
                continue;
            }

            $source = (string) __("widget::bank.promises.from_{$scope}", [], $this->locale);
            $assurances[] = [
                'scope' => $scope,
                'text' => $scope === 'product' ? $lines->implode(' · ') : (string) $lines->first(),
                'note' => $scope === 'product' ? $source : trim($source.' · '.$lines->slice(1)->implode(' · '), " ·\u{00A0}"),
            ];
            $this->note('assurances', $scope, ['promises' => $lines->all()]);
        }

        return $assurances;
    }

    /** One promise in words, with the detail exactly as the store wrote it. */
    private function promiseText(EnrichmentFact $fact): ?string
    {
        $detail = trim((string) $fact->value_text);
        $key = $detail === '' ? $fact->key.'_any' : $fact->key;
        $line = (string) __("widget::bank.promises.{$key}", ['detail' => $detail], $this->locale);

        return str_starts_with($line, 'widget::bank.') ? null : $line;
    }
}
