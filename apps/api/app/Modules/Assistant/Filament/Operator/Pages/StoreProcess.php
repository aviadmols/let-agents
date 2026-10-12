<?php

namespace App\Modules\Assistant\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantBenchReport;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Runs\Models\Run;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One shop's process, for the operator: what was read from the store (products, pages, the index,
 * vocabularies and facts), the last run of every agent on it, the benchmarks' reports with each
 * question's result, and the shop's log. Everything is the chosen shop's only.
 */
final class StoreProcess extends Page
{
    use NeedsShopContext;

    private const LOG = 60;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'store-process';

    protected string $view = 'assistant::operator.store-process';

    /** The report open below the list. */
    #[Url]
    public ?string $report = null;

    /** The log narrowed to one agent. */
    #[Url]
    public ?string $agent = null;

    public static function getNavigationLabel(): string
    {
        return __('assistant::ui.process.title');
    }

    public function getTitle(): string
    {
        return __('assistant::ui.process.title');
    }

    public function getSubheading(): ?string
    {
        return __('assistant::ui.process.subheading');
    }

    public function shopId(): string
    {
        return (string) app(TenantContext::class)->require();
    }

    /** @return array<string, mixed> what was read from the store, in counts */
    public function scanned(): array
    {
        $facts = EnrichmentFact::query()->where('status', '!=', FactStatus::Superseded->value);

        return [
            'products' => [
                'all' => CatalogProduct::query()->count(),
                'active' => CatalogProduct::query()->active()->count(),
                'in_stock' => CatalogProduct::query()->active()->where('in_stock', true)->count(),
            ],
            'content' => CatalogContent::query()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')->all(),
            'index' => RetrievalChunk::query()->selectRaw('source, embedding_model, count(*) as n, max(embedded_at) as last')
                ->groupBy('source', 'embedding_model')->get()->map(fn ($row): array => ['source' => $row->source, 'model' => $row->embedding_model, 'n' => (int) $row->n, 'last' => $row->last])->all(),
            'vocabularies' => EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get(['key', 'version', 'created_at', 'author'])->all(),
            'code_readings' => EnrichmentCodeReading::query()->count(),
            'facts_by_status' => (clone $facts)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'approved_by_kind' => EnrichmentFact::query()->where('status', FactStatus::Approved->value)->selectRaw('kind, count(*) as n')->groupBy('kind')->orderByDesc('n')->pluck('n', 'kind')->all(),
            'approved_by_origin' => EnrichmentFact::query()->where('status', FactStatus::Approved->value)->selectRaw('origin, count(*) as n')->groupBy('origin')->pluck('n', 'origin')->all(),
            'products_with_facts' => EnrichmentFact::query()->where('status', FactStatus::Approved->value)->whereNotNull('product_id')->distinct()->count('product_id'),
        ];
    }

    /** @return Collection<int, Run> the last run of each agent on this shop */
    public function steps(): Collection
    {
        $runs = Run::query()->forShop($this->shopId())->where('created_at', '>=', now()->subDays(60))
            ->orderByDesc('created_at')->limit(2000)->get(['id', 'agent', 'action', 'status', 'summary_key', 'summary_params', 'created_at', 'finished_at', 'cost_usd']);

        return $runs->unique('agent')->sortBy('agent')->values();
    }

    /** @return Collection<int, AssistantBenchReport> */
    public function reports(): Collection
    {
        return AssistantBenchReport::query()->orderByDesc('updated_at')->get(['id', 'kind', 'label', 'questions', 'created_at', 'updated_at']);
    }

    public function openReport(): ?AssistantBenchReport
    {
        return $this->report === null ? null : AssistantBenchReport::query()->find($this->report);
    }

    public function open(string $id): void
    {
        $this->report = $this->report === $id ? null : $id;
    }

    /** @return Collection<int, Run> */
    public function log(): Collection
    {
        return Run::query()->forShop($this->shopId())
            ->when($this->agent, fn ($q, string $agent) => $q->where('agent', $agent))
            ->orderByDesc('created_at')->limit(self::LOG)
            ->get(['id', 'agent', 'action', 'status', 'summary_key', 'summary_params', 'created_at', 'finished_at', 'cost_usd', 'error']);
    }

    public function runUrl(string $id): string
    {
        return route('filament.operator.resources.runs.view', ['record' => $id]);
    }
}
