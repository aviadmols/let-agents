<?php

namespace App\Modules\Enrichment\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\DecideSchemaProposal;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The shop's own vocabulary, discovered from its products: what code counted on the sample, the
 * vocabulary a model wrote from it with each pattern's measured share, what the reviewer of the
 * other family dropped and found missing; and the operator's decision. Nothing is read with a
 * proposal until it is approved.
 */
final class SchemaDiscovery extends Page
{
    use NeedsShopContext;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'enrichment/schema';

    protected string $view = 'enrichment::operator.schema-discovery';

    #[Url]
    public ?string $proposal = null;

    public static function getNavigationLabel(): string
    {
        return __('enrichment::ui.schema.title');
    }

    public function getTitle(): string
    {
        return __('enrichment::ui.schema.title');
    }

    public function getSubheading(): ?string
    {
        return __('enrichment::ui.schema.subheading');
    }

    /** @return Collection<int, EnrichmentSchemaProposal> */
    public function proposals(): Collection
    {
        return EnrichmentSchemaProposal::query()->latest()->limit(20)
            ->get(['id', 'status', 'sampled', 'template', 'summary', 'created_at', 'decided_at', 'vocabulary_id']);
    }

    /** The one open below the list: the chosen one, or the latest. */
    public function current(): ?EnrichmentSchemaProposal
    {
        return $this->proposal !== null
            ? EnrichmentSchemaProposal::query()->find($this->proposal)
            : EnrichmentSchemaProposal::query()->latest()->first();
    }

    /** How many products the shop has, beside the sample's size. */
    public function activeProducts(): int
    {
        return CatalogProduct::query()->active()->count();
    }

    /**
     * One row per product type and attribute, as the operator decides on it: what it is, its share
     * in the sample, its share in the whole catalog once read (approved facts), where it was read
     * from, and the decision, with the ones code or the reviewer removed and why.
     *
     * @return list<array{part: string, key: string, label: string, kind: string, values: list<array{label: string, share: float}>, sample: float|null, catalog: float|null, source: string, decision: string, why: string}>
     */
    public function rows(EnrichmentSchemaProposal $proposal): array
    {
        $coverage = $proposal->coverage ?? [];
        $read = $this->read($proposal);
        // Who wrote each field: in an operator's edit, what came from the proposal it was made on is that proposal's writer's.
        $base = isset($proposal->review['based_on']) ? EnrichmentSchemaProposal::query()->find($proposal->review['based_on']) : null;
        $baseKeys = $base === null ? [] : array_flip([...array_column((array) ($base->proposed['product_types'] ?? []), 'key'), ...array_column((array) ($base->proposed['attributes'] ?? []), 'key')]);
        $author = fn (string $key): string => self::who(isset($baseKeys[$key]) ? (string) ($base->review['writer']['model'] ?? '') : (string) ($proposal->review['writer']['model'] ?? ''));
        $decision = $proposal->status;
        $rows = [];

        foreach ((array) ($proposal->proposed['product_types'] ?? []) as $type) {
            $key = (string) $type['key'];
            $rows[] = ['part' => 'type', 'key' => $key, 'label' => (string) ($type['label']['he'] ?? $key), 'kind' => '', 'values' => [],
                'sample' => (float) ($coverage['product_types'][$key] ?? 0), 'catalog' => $read['types'][$key]['share'] ?? null,
                'source' => self::sources($read['types'][$key]['sources'] ?? []), 'decision' => $decision, 'why' => '', 'author' => $author($key)];
        }
        foreach ((array) ($proposal->proposed['attributes'] ?? []) as $attribute) {
            $key = (string) $attribute['key'];
            $kind = (string) ($attribute['type'] ?? 'number');
            $values = array_map(fn (array $v): array => ['label' => (string) ($v['label']['he'] ?? $v['key']), 'share' => (float) ($coverage['attributes'][$key]['values'][$v['key']] ?? 0)], (array) ($attribute['values'] ?? []));
            $rows[] = ['part' => 'attribute', 'key' => $key, 'label' => (string) ($attribute['label']['he'] ?? $key),
                'kind' => $kind === 'number' ? 'number · '.($attribute['dimension'] ?? '') : $kind, 'values' => $values,
                'sample' => (float) ($coverage['attributes'][$key]['share'] ?? 0), 'catalog' => $read['attributes'][$key]['share'] ?? null,
                'source' => self::sources($read['attributes'][$key]['sources'] ?? []) ?: (string) ($proposal->review['writer']['sources'][$key] ?? ''),
                'decision' => $decision, 'why' => '', 'author' => $author($key)];
        }
        foreach ((array) ($proposal->review['dropped'] ?? []) as $drop) {
            $rows[] = ['part' => $drop['kind'] === 'product_type' ? 'type' : 'attribute', 'key' => (string) $drop['key'], 'label' => '', 'kind' => (string) $drop['kind'],
                'values' => [], 'sample' => null, 'catalog' => null, 'source' => '', 'decision' => 'removed_by_'.($drop['by'] ?? 'reviewer'), 'why' => (string) $drop['why'], 'author' => ''];
        }

        return $rows;
    }

    /**
     * What code read with an approved proposal, from its approved facts: the share of the active
     * products each type and attribute holds, and the sources it was read from.
     *
     * @return array{types: array<string, array{share: float, sources: array<string, int>}>, attributes: array<string, array{share: float, sources: array<string, int>}>}
     */
    private function read(EnrichmentSchemaProposal $proposal): array
    {
        $out = ['types' => [], 'attributes' => []];
        $active = $this->activeProducts();
        if ($proposal->vocabulary_id === null || $active === 0) {
            return $out;
        }

        $products = [];
        EnrichmentFact::query()->where('vocabulary_id', $proposal->vocabulary_id)->where('status', FactStatus::Approved)->whereNotNull('product_id')
            ->select(['id', 'product_id', 'kind', 'key', 'value_text', 'evidence'])
            ->lazyById(2000)->each(function (EnrichmentFact $fact) use (&$out, &$products): void {
                [$part, $key] = $fact->kind === FactKind::Type ? ['types', (string) $fact->value_text] : ['attributes', $fact->key];
                $products[$part][$key][$fact->product_id] = true;
                $source = (string) ($fact->evidence['source'] ?? 'title');
                $out[$part][$key]['sources'][$source] = ($out[$part][$key]['sources'][$source] ?? 0) + 1;
            });
        foreach ($products as $part => $keys) {
            foreach ($keys as $key => $ids) {
                $out[$part][$key]['share'] = round(count($ids) / $active, 3);
            }
        }

        return $out;
    }

    /**
     * Who wrote and who reviewed the proposal, from the first version: an operator's edit names the
     * proposal it was made on, and that one its writer and reviewer.
     *
     * @return list<array{at: string, operator: bool, writer: string, reviewer: string}>
     */
    public function lineage(EnrichmentSchemaProposal $proposal): array
    {
        $steps = [];
        for ($p = $proposal, $n = 0; $p !== null && $n < 6; $n++) {
            $writer = (string) ($p->review['writer']['model'] ?? '');
            $steps[] = ['at' => (string) $p->created_at?->format('d/m/Y H:i'), 'operator' => $writer === 'operator', 'writer' => self::who($writer), 'reviewer' => self::who((string) ($p->review['reviewer'] ?? ''))];
            $p = isset($p->review['based_on']) ? EnrichmentSchemaProposal::query()->find($p->review['based_on']) : null;
        }

        return array_reverse($steps);
    }

    /** A model with its company, or the operator. */
    private static function who(string $model): string
    {
        if ($model === '') {
            return '-';
        }
        if ($model === 'operator') {
            return (string) __('enrichment::ui.schema.by.operator');
        }
        $company = match (true) {
            str_starts_with($model, 'claude') => 'Anthropic',
            str_starts_with($model, 'gemini') => 'Google',
            (bool) preg_match('/^(gpt|o\d|text-)/', $model) => 'OpenAI',
            default => null,
        };

        return $company === null ? $model : $model.' ('.$company.')';
    }

    /** @param  array<string, int>  $sources */
    private static function sources(array $sources): string
    {
        arsort($sources);

        return implode(' / ', array_keys($sources));
    }

    /**
     * Who writes the vocabulary and who reviews it, as set on the agents screen.
     *
     * @return array{writer: AgentModel, reviewer: AgentModel, same_family: bool}
     */
    public function models(): array
    {
        $writer = AgentModel::from('enrichment.schema_writer_provider', 'enrichment.schema_writer_model');
        $reviewer = AgentModel::from('enrichment.schema_reviewer_provider', 'enrichment.schema_reviewer_model');

        return ['writer' => $writer, 'reviewer' => $reviewer, 'same_family' => $writer->provider->family() === $reviewer->provider->family()];
    }

    public function open(string $id): void
    {
        $this->proposal = $id;
    }

    /** Counts a new sample, and writes and reviews a vocabulary from it, in the background. */
    public function discover(): void
    {
        $shop = $this->shop();
        Artisan::queue('enrichment', ['step' => 'schema', 'target' => $shop->slug])->onQueue('long');

        Notification::make()->success()->title(__('enrichment::ui.schema.started', ['shop' => $shop->name]))->send();
    }

    public function decide(string $id, bool $approve): void
    {
        $proposal = EnrichmentSchemaProposal::query()->find($id);
        $summary = $proposal === null ? null : app(DecideSchemaProposal::class)->handle($proposal, $approve);

        if ($summary === null) {
            Notification::make()->warning()->title(__('enrichment::ui.schema.cannot_decide'))->send();

            return;
        }

        Notification::make()->success()->title($summary)->send();
    }

    /** Reads the whole catalog in code with the shop's vocabularies, now, in the background. */
    public function readCatalog(): void
    {
        $shop = $this->shop();
        Artisan::queue('enrichment', ['step' => 'code', 'target' => $shop->slug])->onQueue('long');

        Notification::make()->success()->title(__('enrichment::ui.schema.reading', ['shop' => $shop->name]))->send();
    }

    private function shop(): Shop
    {
        return Shop::query()->findOrFail(app(TenantContext::class)->require());
    }
}
