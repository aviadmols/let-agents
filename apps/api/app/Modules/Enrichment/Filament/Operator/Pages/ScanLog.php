<?php

namespace App\Modules\Enrichment\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Everything that happened to one product, in order: what code read, what a model was asked and
 * answered, what code approved or refused and why, what checkers said, and the relations built
 * on top. Below it, the problems that come up most in answers, to see what to improve.
 */
final class ScanLog extends Page
{
    use NeedsShopContext;

    private const SEARCH_RESULTS = 12;

    private const TOP_PROBLEMS = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'enrichment/scan-log';

    protected string $view = 'enrichment::operator.scan-log';

    #[Url]
    public ?string $product = null;

    public string $search = '';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::ui.scan_log.title');
    }

    public function getTitle(): string
    {
        return __('enrichment::ui.scan_log.title');
    }

    public function getSubheading(): ?string
    {
        return __('enrichment::ui.scan_log.subheading');
    }

    public function pick(string $productId): void
    {
        $this->product = $productId;
        $this->search = '';
    }

    /** @return Collection<int, CatalogProduct> */
    public function matches(): Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return $this->unscoped(fn () => CatalogProduct::query()
            ->with('shop:id,name')
            ->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('external_id', $term))
            ->orderBy('title')
            ->limit(self::SEARCH_RESULTS)
            ->get(['id', 'shop_id', 'external_id', 'title']));
    }

    /** @return array<string, mixed>|null */
    public function log(): ?array
    {
        if ($this->product === null) {
            return null;
        }

        $product = $this->unscoped(fn () => CatalogProduct::query()->with('shop:id,name')->find($this->product));

        if ($product === null) {
            return null;
        }

        return app(TenantContext::class)->run($product->shop_id, fn (): array => [
            'product' => $product,
            'reading' => EnrichmentCodeReading::query()->where('product_id', $product->id)->first(),
            'facts' => EnrichmentFact::query()->with('batch:id,task,review_tier')->where('product_id', $product->id)->orderBy('created_at')->orderBy('id')->get(),
            'items' => EnrichmentBatchItem::query()->with('batch:id,task,review_tier,model,prompt_version,created_at')->where('subject_id', $product->id)->orderBy('created_at')->get(),
            'relations_from' => EnrichmentProductRelation::query()->with('related:id,external_id,title')->where('product_id', $product->id)->orderBy('kind')->orderByDesc('score')->get(),
            'relations_to' => EnrichmentProductRelation::query()->with('product:id,external_id,title')->where('related_product_id', $product->id)->orderBy('kind')->orderByDesc('score')->limit(30)->get(),
            'quality' => $this->quality(),
        ]);
    }

    /**
     * The shop's most common answer problems (the part before ":" is the code) and fact status
     * by origin.
     *
     * @return array{problems: array<string, int>, statuses: array<string, array<string, int>>}
     */
    private function quality(): array
    {
        $problems = [];

        EnrichmentBatchItem::query()->whereNotNull('problems')->select(['id', 'problems'])->lazyById(500)->each(function (EnrichmentBatchItem $item) use (&$problems): void {
            foreach ((array) $item->problems as $problem) {
                $code = strtok((string) $problem, ':');
                $problems[$code] = ($problems[$code] ?? 0) + 1;
            }
        });

        arsort($problems);

        $statuses = [];
        foreach (EnrichmentFact::query()->selectRaw('origin, status, count(*) as n')->groupBy('origin', 'status')->get() as $row) {
            $origin = $row->origin instanceof BackedEnum ? $row->origin->value : (string) $row->origin;
            $status = $row->status instanceof BackedEnum ? $row->status->value : (string) $row->status;
            $statuses[$origin][$status] = (int) $row->n;
        }

        return ['problems' => array_slice($problems, 0, self::TOP_PROBLEMS, true), 'statuses' => $statuses];
    }

    private function unscoped(callable $callback): mixed
    {
        return app(TenantContext::class)->runUnscoped($callback);
    }
}
