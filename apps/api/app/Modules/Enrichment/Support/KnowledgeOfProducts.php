<?php

namespace App\Modules\Enrichment\Support;

use App\Core\Localization\Money;
use App\Modules\Admin\Contracts\KnowledgeProducts;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Merchant\Pages\ScanSummary;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\RequiredGaps;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What the scan understood of the products, on the Store knowledge screen: the counts, a row to pick from, and one
 * product's tags with where each came from, the details it lacks and what its photo shows. The same facts the scan
 * summary shows (ScanSummary), where "Something wrong?" leads. Runs inside the shop's TenantContext. No model.
 */
final class KnowledgeOfProducts implements KnowledgeProducts
{
    public function numbers(Shop $shop): array
    {
        $at = Run::query()->forShop($shop->id)->where('action', 'catalog.sync')->where('status', RunStatus::Succeeded)->latest('started_at')->value('finished_at');

        return [
            'products' => CatalogProduct::query()->active()->count(),
            'photos' => RetrievalImage::query()->whereNotNull('caption')->count(),
            'pages' => CatalogContent::query()->active()->where('type', CatalogContent::PAGE)->count(),
            'posts' => CatalogContent::query()->active()->where('type', CatalogContent::POST)->count(),
            'read_at' => $at === null ? null : Carbon::parse($at)->toIso8601String(),
        ];
    }

    public function strip(Shop $shop, string $search, int $limit): array
    {
        $gaps = $this->gaps($shop);
        $query = CatalogProduct::query()->active();
        if (($words = trim($search)) !== '') {
            $query->where(fn (Builder $q) => $q->where('title', 'like', '%'.addcslashes($words, '%_\\').'%')->orWhere('sku', 'like', '%'.addcslashes($words, '%_\\').'%'));
        } else {
            // with no words: a few that miss a detail first, so the dot shows what to look at; then the ones something
            // was understood of, so the product that opens first has something to show
            if ($gaps !== []) {
                $query->orderByRaw('case when id in ('.implode(',', array_fill(0, min(count($gaps), 200), '?')).') then 0 else 1 end', array_slice(array_keys($gaps), 0, 200));
            }
            $query->orderByRaw('case when exists (select 1 from enrichment_facts f where f.product_id = catalog_products.id and f.status = ?) then 0 else 1 end', [FactStatus::Approved->value]);
        }

        return $query->orderBy('title')->orderBy('id')->limit($limit)->get(['id', 'title', 'image_url'])
            ->map(fn (CatalogProduct $p): array => ['id' => (string) $p->id, 'title' => (string) $p->title, 'picture' => $p->image_url, 'missing' => isset($gaps[(string) $p->id])])
            ->all();
    }

    public function product(Shop $shop, string $id): ?array
    {
        $product = CatalogProduct::query()->active()->find($id);
        if ($product === null) {
            return null;
        }
        $facts = EnrichmentFact::query()->current()->where('product_id', $product->id)->where('status', FactStatus::Approved)
            ->whereIn('kind', ScanSummary::TAG_KINDS)->with('vocabulary')->orderBy('kind')->orderBy('id')->get();
        $tags = [];
        foreach ($facts as $fact) {
            $label = trim($fact->valueLabel());
            if ($label !== '' && ! isset($tags[mb_strtolower($label)])) {
                $tags[mb_strtolower($label)] = ['label' => $label, 'from' => self::from($fact)];
            }
        }
        $image = RetrievalImage::query()->where('product_id', $product->id)->first(['caption']);
        $fix = null;
        try {
            $fix = ScanSummary::getUrl(['tab' => 'products', 'open' => (string) $product->id], panel: 'merchant', tenant: $shop);
        } catch (Throwable) {
            // no merchant panel here (a command, a test without one): nothing to link to
        }

        return [
            'id' => (string) $product->id,
            'title' => (string) $product->title,
            'picture' => $product->image_url,
            'price' => $product->price === null || $product->price === '' ? null : Money::price((float) $product->price, (string) ($shop->currency ?: 'ILS')),
            'url' => $product->url,
            'tags' => array_values($tags),
            'missing' => array_values(array_unique(array_column($this->gaps($shop)[(string) $product->id] ?? [], 'label'))),
            'saw' => is_string($image?->caption) && trim($image->caption) !== '' ? trim($image->caption) : null,
            'fix' => $fix,
        ];
    }

    /** Where a tag came from, in the screen's words: the title, the product's page, the photo, the team, or understood. */
    private static function from(EnrichmentFact $fact): string
    {
        if ($fact->origin === FactOrigin::Person) {
            return 'team';
        }
        $source = (string) ($fact->evidence['source'] ?? '');

        return match (true) {
            $source === 'title' => 'title',
            $source === 'image' => 'photo',
            $fact->kind === FactKind::Category || in_array($source, ['category', 'variant', 'field', 'attribute', 'attributes', 'description'], true) => 'page',
            default => 'understood',
        };
    }

    /** @return array<string, list<array{key: string, label: string}>> the details each product lacks, by product id (kept as the scan summary keeps them) */
    private function gaps(Shop $shop): array
    {
        return Cache::remember('enrichment:knowledge-gaps:'.$shop->id.':'.app()->getLocale(), 600, function (): array {
            $by = [];
            foreach (RequiredGaps::of(locale: app()->getLocale()) as $group) {
                foreach ($group['missing'] as $product) {
                    $by[(string) $product['id']][] = ['key' => (string) $group['attribute'], 'label' => (string) $group['attribute_label']];
                }
            }

            return $by;
        });
    }
}
