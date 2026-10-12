<?php

namespace App\Modules\Enrichment\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Actions\DecideSchemaProposal;
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
