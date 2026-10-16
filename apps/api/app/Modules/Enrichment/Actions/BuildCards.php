<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentCard;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Support\Cards;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Writes the cards every model reads (Support\Cards): each product's, each page's and post's, and the
 * store's. Code only, no model, no cost. A card is written only when what it says changed, so a run
 * over a whole shop is cheap; a sync's changed products are carded minutes after it (RebuildCards),
 * and the nightly reading cards what it approved.
 */
final class BuildCards
{
    public const AGENT = 'enrichment.cards';

    public const ACTION = 'enrichment.build_cards';

    private const CHUNK = 200;

    private const POLICY_PAGES = 20;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly Cards $cards,
    ) {}

    /** @param  list<string>|null  $externalIds  only these products (a sync's changes); null: the whole shop */
    public function handle(string $shopId, ?array $externalIds = null, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->build($run, $shopId, $externalIds)),
        );
    }

    /** @param  list<string>|null  $externalIds */
    private function build(RunContext $run, string $shopId, ?array $externalIds): void
    {
        $shop = Shop::query()->whereKey($shopId)->first();
        // The labels in the shop's own language: a card is read in the language its store writes in.
        $before = app()->getLocale();
        app()->setLocale((string) ($shop?->content_locale ?: 'he'));
        $counts = ['products' => 0, 'contents' => 0, 'changed' => 0, 'removed' => 0];

        try {
            $products = CatalogProduct::query()->whereNull('removed_at')
                ->when($externalIds !== null, fn (Builder $q) => $q->whereIn('external_id', $externalIds));
            $products->chunkById(self::CHUNK, function (Collection $chunk) use ($shopId, &$counts): void {
                $ids = $chunk->pluck('id')->all();
                $facts = EnrichmentFact::query()->with('vocabulary')->whereIn('product_id', $ids)->where('status', FactStatus::Approved)
                    ->orderBy('kind')->orderBy('key')->orderBy('id')->get()->groupBy('product_id');
                $relations = EnrichmentProductRelation::query()->whereIn('product_id', $ids)->orderByDesc('score')->orderBy('id')->get()->groupBy('product_id');
                $related = CatalogProduct::query()->whereNull('removed_at')
                    ->whereIn('id', $relations->flatten()->pluck('related_product_id')->unique()->values()->all())
                    ->get(['id', 'external_id', 'title'])
                    ->mapWithKeys(fn (CatalogProduct $p): array => [$p->id => ['id' => (string) $p->external_id, 'title' => (string) $p->title]])->all();

                foreach ($chunk as $product) {
                    $card = $this->cards->product($product, $facts->get($product->id, collect()), $relations->get($product->id, collect()), $related);
                    $counts['changed'] += $this->keep($shopId, EnrichmentCard::PRODUCT, $product->id, $card) ? 1 : 0;
                    $counts['products']++;
                }
            });

            if ($externalIds === null) {
                // A product the store stopped publishing has no card to read.
                $counts['removed'] += EnrichmentCard::query()->where('kind', EnrichmentCard::PRODUCT)
                    ->whereNotIn('subject_id', CatalogProduct::query()->whereNull('removed_at')->select('id'))->delete();
                $this->contents($shopId, $counts);
                $this->store($shopId, $shop, $counts);
            }
        } finally {
            app()->setLocale($before);
        }

        $run->output($counts)->summary('enrichment::runs.cards_built', [
            'products' => number_format($counts['products']),
            'contents' => number_format($counts['contents']),
            'changed' => number_format($counts['changed']),
        ]);
    }

    /** @param  array<string, int>  $counts */
    private function contents(string $shopId, array &$counts): void
    {
        CatalogContent::query()->whereNull('removed_at')->chunkById(self::CHUNK, function (Collection $chunk) use ($shopId, &$counts): void {
            $promises = EnrichmentFact::query()->with('vocabulary')->where('kind', FactKind::Promise)->where('status', FactStatus::Approved)
                ->whereIn('content_id', $chunk->pluck('id')->all())->orderBy('key')->orderBy('id')->get()->groupBy('content_id');
            $named = $chunk->flatMap(fn (CatalogContent $c): array => array_map('strval', (array) $c->product_external_ids))->unique()->values()->all();
            $titles = CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $named)->pluck('title', 'external_id')->all();

            foreach ($chunk as $content) {
                $products = [];
                foreach ((array) $content->product_external_ids as $id) {
                    if (isset($titles[(string) $id])) {
                        $products[] = ['id' => (string) $id, 'title' => (string) $titles[(string) $id]];
                    }
                }
                $card = $this->cards->content($content, $promises->get($content->id, collect()), $products);
                $counts['changed'] += $this->keep($shopId, EnrichmentCard::CONTENT, $content->id, $card) ? 1 : 0;
                $counts['contents']++;
            }
        });

        $counts['removed'] += EnrichmentCard::query()->where('kind', EnrichmentCard::CONTENT)
            ->whereNotIn('subject_id', CatalogContent::query()->whereNull('removed_at')->select('id'))->delete();
    }

    /** @param  array<string, int>  $counts */
    private function store(string $shopId, ?Shop $shop, array &$counts): void
    {
        // What the store promises on its own pages (returns, delivery, warranty), approved, and those pages.
        $policies = EnrichmentFact::query()->with('vocabulary')->where('kind', FactKind::Promise)->whereNull('product_id')->whereNotNull('content_id')
            ->where('status', FactStatus::Approved)->orderBy('key')->orderBy('id')->get();
        $pages = CatalogContent::query()->whereNull('removed_at')->whereNotNull('url')
            ->where(fn (Builder $q) => $q->where('external_id', 'like', 'policy-%')->orWhereIn('id', $policies->pluck('content_id')->unique()->values()->all()))
            ->orderBy('title')->limit(self::POLICY_PAGES)->get(['title', 'url'])
            ->map(fn (CatalogContent $c): array => ['title' => (string) $c->title, 'url' => (string) $c->url])->all();

        $card = $this->cards->store((string) ($shop?->name ?? ''), null, $policies, $pages);
        $counts['changed'] += $this->keep($shopId, EnrichmentCard::STORE, EnrichmentCard::STORE, $card) ? 1 : 0;
    }

    /** Written only when what it says changed. @param array<string, mixed> $card */
    private function keep(string $shopId, string $kind, string $subjectId, array $card): bool
    {
        $hash = Cards::hash($card);
        $existing = EnrichmentCard::query()->where('kind', $kind)->where('subject_id', $subjectId)->first();

        if ($existing !== null && $existing->hash === $hash) {
            return false;
        }

        EnrichmentCard::query()->updateOrCreate(
            ['shop_id' => $shopId, 'kind' => $kind, 'subject_id' => $subjectId],
            ['card' => $card, 'hash' => $hash, 'built_at' => now()],
        );

        return true;
    }
}
