<?php

namespace App\Modules\Catalog\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogProductChoice;
use App\Modules\Catalog\Support\PageChoices;
use App\Modules\Catalog\Support\StoreRest;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads, from each product's own page, the choices the store asks for before "add to cart" and
 * its feed does not carry (catalog.page_choices): a length, a size, priced each (PageChoices). For
 * stores whose cart takes these from a form, like WooCommerce with Product Add-Ons; Shopify's
 * options are its variants and come with the feed.
 *
 * Slow and sparing, once a day: a few hundred pages at most, a pause between two, and a page is
 * read again only when it is due: never read; its product changed; it asks for choices and was
 * read a day ago; it asks for none and was read a week ago. A page whose form did not change is
 * not read into fields again. A run is written only when something changed. Code only.
 *
 * On every platform (catalog.page_sizes), the size the page says the product itself is: a ring of one
 * size kept outside its variants ("מידה נוכחית: 54"), for search to read as the product's size.
 */
final class ReadPageChoices
{
    public const AGENT = 'catalog.syncer';

    public const ACTION = 'catalog.read_choices';

    /** A page with choices is read again after this; prices move. */
    private const WITH_CHOICES_HOURS = 20;

    /** A page that asks for nothing is looked at again after this. */
    private const WITHOUT_CHOICES_DAYS = 7;

    /** A page that could not be read is tried again the next day, this many times in a row. */
    private const MAX_FAILURES = 5;

    private const TIMEOUT_SECONDS = 20;

    /** @var Closure(int): void */
    private Closure $pause;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        ?Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /** The run when something changed; null when nothing did, or the shop does not read its pages. */
    public function handle(string $shopId, ?int $limit = null, RunTrigger $trigger = RunTrigger::Schedule, ?CarbonInterface $until = null): ?Run
    {
        $connection = $this->tenant->run($shopId, fn () => StoreConnection::query()->first());
        // Add-on forms on WooCommerce (Shopify's options are its variants); a product's own size anywhere.
        $forms = Features::enabled('catalog.page_choices', $shopId) && $connection !== null && $connection->platform !== 'shopify';
        $sizes = Features::enabled('catalog.page_sizes', $shopId);

        // A site that struggled a moment ago is left alone a while (StoreRest).
        if ((! $forms && ! $sizes) || StoreRest::resting($shopId)) {
            return null;
        }

        $limit ??= (int) Settings::get('catalog.page_choices_per_run', $shopId);
        $delay = (int) Settings::get('catalog.page_choices_delay_ms', $shopId);
        $counts = ['read' => 0, 'same' => 0, 'changed' => 0, 'with_choices' => 0, 'failed' => 0];
        $changed = [];

        $this->tenant->run($shopId, function () use ($shopId, $limit, $delay, $forms, $sizes, $until, &$counts, &$changed): void {
            $down = 0;

            foreach ($this->due($limit) as $i => [$product, $row]) {
                // A part of a reading (ReadPageChoicesJob): what is left, the next part reads.
                if ($until !== null && now()->gte($until)) {
                    break;
                }

                if ($i > 0) {
                    ($this->pause)($delay);
                }

                $outcome = $this->read($product, $row, $forms, $sizes);

                // The site answers with errors, or not at all, again and again: it struggles; stop and let it be.
                $down = $outcome === 'down' ? $down + 1 : 0;
                if ($down >= StoreRest::TIMES) {
                    StoreRest::rest($shopId);
                    $counts['failed']++;
                    $counts['read']++;

                    break;
                }

                $outcome = $outcome === 'down' ? 'failed' : $outcome;
                $counts[$outcome]++;
                $counts['read']++;

                if ($outcome === 'changed') {
                    $changed[] = (string) $product->external_id;
                }
            }

            // Counted here, not in SQL: a json column has no equality in Postgres.
            $counts['with_choices'] = CatalogProductChoice::query()->get(['fields'])->filter(fn (CatalogProductChoice $c): bool => $c->fields !== [])->count();
        });

        if ($changed === []) {
            return null;
        }

        $run = $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $run->output($counts + ['products' => array_slice($changed, 0, 50)])
                ->summary('catalog::runs.choices_read', ['read' => (string) $counts['read'], 'changed' => (string) $counts['changed'], 'with' => (string) $counts['with_choices']]),
        );

        // A size or a choice a page says reaches the search now, not with the next night's index.
        event(new CatalogProductsChanged($shopId, $changed));

        return $run;
    }

    /** Whether any of the shop's product pages is due to be read. */
    public function anyDue(string $shopId): bool
    {
        return $this->tenant->run($shopId, fn (): bool => $this->due(1) !== []);
    }

    /**
     * The products whose page is due, the most needed first, at most $limit.
     *
     * @return list<array{0: CatalogProduct, 1: CatalogProductChoice|null}>
     */
    private function due(int $limit): array
    {
        $rows = CatalogProductChoice::query()->get()->keyBy('product_id');
        $now = now();
        $due = [];

        CatalogProduct::query()->whereNull('removed_at')->whereNotNull('url')->orderBy('id')
            ->get(['id', 'external_id', 'url', 'price', 'hash'])
            ->each(function (CatalogProduct $product) use ($rows, $now, &$due): void {
                /** @var CatalogProductChoice|null $row */
                $row = $rows->get($product->id);
                $rank = match (true) {
                    $row === null => 0,
                    $row->product_hash !== $product->hash => 1,
                    $row->failures > 0 => $row->failures < self::MAX_FAILURES && $row->checked_at?->lt($now->copy()->subDay()) ? 2 : null,
                    $row->fields !== [] => $row->checked_at === null || $row->checked_at->lt($now->copy()->subHours(self::WITH_CHOICES_HOURS)) ? 3 : null,
                    default => $row->checked_at === null || $row->checked_at->lt($now->copy()->subDays(self::WITHOUT_CHOICES_DAYS)) ? 4 : null,
                };

                if ($rank !== null) {
                    $due[] = [$rank, $product, $row];
                }
            });

        usort($due, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $one): array => [$one[1], $one[2]], array_slice($due, 0, max(0, $limit)));
    }

    /** @return 'same'|'changed'|'failed'|'down' down: the site struggles (StoreRest), not this page */
    private function read(CatalogProduct $product, ?CatalogProductChoice $row, bool $forms = true, bool $sizes = false): string
    {
        $row ??= new CatalogProductChoice(['product_id' => $product->id, 'fields' => []]);

        try {
            $response = Http::withHeaders(['User-Agent' => 'LetsSearch/1.0 (+https://agents.lets.co.il)', 'Accept' => 'text/html'])
                ->timeout(self::TIMEOUT_SECONDS)->get((string) $product->url);
        } catch (Throwable) {
            $response = null;
        }

        if ($response === null || ! $response->successful()) {
            $row->fill(['failures' => $row->failures + 1, 'checked_at' => now()])->save();

            return StoreRest::struggling($response) ? 'down' : 'failed';
        }

        $body = (string) $response->body();
        $form = $forms ? PageChoices::form($body) : null;
        $size = $sizes ? PageChoices::size($body) : null;
        $hash = $form === null && $size === null ? null : hash('sha256', ($form ?? '').'|'.json_encode($size));

        // The same form and size for the same product: nothing to read again.
        if ($row->exists && $row->form_hash === $hash && $row->product_hash === $product->hash) {
            $row->fill(['checked_at' => now(), 'failures' => 0])->save();

            return 'same';
        }

        $fields = $form === null ? [] : PageChoices::read($form, $product->price === null ? null : (float) $product->price);
        $different = ! $row->exists || $row->fields !== $fields || (float) $row->size !== (float) ($size['size'] ?? 0);
        $row->fill([
            'fields' => $fields,
            'size' => $size['size'] ?? null,
            'size_label' => $size['label'] ?? null,
            'form_hash' => $hash,
            'product_hash' => $product->hash,
            'checked_at' => now(),
            'failures' => 0,
        ] + ($different ? ['changed_at' => now()] : []))->save();

        return $different && ($fields !== [] || $size !== null || $row->wasChanged('fields') || $row->wasChanged('size')) ? 'changed' : 'same';
    }
}
