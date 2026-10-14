<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\ProductDigest;
use App\Modules\Enrichment\Scanning\PromiseScanner;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * What the store promises, read in code from its own pages and from each product's text.
 *
 * A shopper deciding on a product wants to know two kinds of thing the product page rarely says
 * next to the price: what the shop stands behind — a refund within fourteen days, free delivery
 * over a sum, two years of warranty — and what the product itself is: hand made, all cotton,
 * made somewhere. The first lives on the shop's pages, which is why the plugin shares pages; the
 * second is in the product's own description.
 *
 * Every promise is stored with the sentence it was read from, and its detail is a piece of that
 * sentence. No model is called, so this costs nothing and gives the same answer every time.
 */
final class ReadPromisesInCode
{
    public const AGENT = 'enrichment.promise_reader';

    public const ACTION = 'enrichment.read_promises';

    /** Pages worth reading: the whole page, not a snippet, but not a novel either. */
    private const MAX_PAGE_CHARS = 20000;

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
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->read($run, $shopId)),
        );
    }

    private function read(RunContext $run, string $shopId): void
    {
        $status = Features::enabled('enrichment.auto_approve', $shopId) ? FactStatus::Approved : FactStatus::NeedsPerson;
        $counts = ['pages' => 0, 'shop_promises' => 0, 'products' => 0, 'product_promises' => 0];
        $samples = [];

        DB::transaction(function () use ($shopId, $status, &$counts, &$samples): void {
            // Code's earlier promises, unless a person decided on them: a page the shop rewrote
            // must be able to take a promise away, not only add one.
            EnrichmentFact::query()
                ->where('kind', FactKind::Promise)
                ->where('origin', FactOrigin::Code)
                ->where('status', '!=', FactStatus::Superseded)
                ->whereNull('decided_by')
                ->update(['status' => FactStatus::Superseded, 'updated_at' => now()]);

            // What the shop promises, from its pages. The first page that says a thing owns it,
            // so a promise repeated in the footer of every page is stored once; the store's
            // policies are read first, so its returns page owns what it says of returns.
            $said = [];

            CatalogContent::query()->active()->where('type', 'page')->orderBy('external_id')->get()
                ->sortBy(fn (CatalogContent $page): array => [$page->importance(), (string) $page->external_id])
                ->each(function (CatalogContent $page) use ($shopId, $status, &$counts, &$said, &$samples): void {
                    $counts['pages']++;
                    $text = mb_substr(trim(($page->title ?? '')."\n".($page->body ?? $page->excerpt ?? '')), 0, self::MAX_PAGE_CHARS);

                    foreach (PromiseScanner::shop($text) as $promise) {
                        if (isset($said[$promise['key']])) {
                            continue;
                        }
                        $said[$promise['key']] = true;
                        $counts['shop_promises']++;

                        if (count($samples) < 10) {
                            $samples[] = ['scope' => 'shop', 'page' => $page->title, 'key' => $promise['key'], 'detail' => $promise['detail']];
                        }

                        EnrichmentFact::query()->create([
                            'shop_id' => $shopId,
                            'content_id' => $page->id,
                            'kind' => FactKind::Promise,
                            'key' => $promise['key'],
                            'value_text' => $promise['detail'],
                            'quote' => $promise['quote'],
                            'origin' => FactOrigin::Code,
                            'status' => $status,
                            'status_reason' => 'code_page',
                            'input_hash' => hash('sha256', $page->hash.'|'.$promise['key']),
                            'model' => 'code',
                        ]);
                    }
                });

            // What each product says about itself.
            CatalogProduct::query()->active()->orderBy('external_id')
                ->each(function (CatalogProduct $product) use ($shopId, $status, &$counts, &$samples): void {
                    $counts['products']++;
                    $text = trim(implode("\n", array_filter(ProductDigest::sections($product))));

                    foreach (PromiseScanner::product($text) as $promise) {
                        $counts['product_promises']++;

                        if (count($samples) < 20) {
                            $samples[] = ['scope' => 'product', 'product' => $product->external_id, 'key' => $promise['key'], 'detail' => $promise['detail']];
                        }

                        EnrichmentFact::query()->create([
                            'shop_id' => $shopId,
                            'product_id' => $product->id,
                            'kind' => FactKind::Promise,
                            'key' => $promise['key'],
                            'value_text' => $promise['detail'],
                            'quote' => $promise['quote'],
                            'origin' => FactOrigin::Code,
                            'status' => $status,
                            'status_reason' => 'code_product_text',
                            'input_hash' => hash('sha256', $product->hash.'|'.$promise['key']),
                            'model' => 'code',
                        ]);
                    }
                });
        });

        $run->output($counts + ['samples' => $samples])->summary('enrichment::runs.read_promises', [
            'pages' => number_format($counts['pages']),
            'shop_promises' => number_format($counts['shop_promises']),
            'product_promises' => number_format($counts['product_promises']),
        ]);
    }
}
