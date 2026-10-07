<?php

namespace App\Modules\Enrichment\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Actions\AuditContentReading;
use App\Modules\Enrichment\Actions\PublishContentRules;
use App\Modules\Enrichment\Models\EnrichmentContentRules;
use App\Modules\Enrichment\Models\EnrichmentRuleProposal;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Every change the audit has proposed to how articles are read, and every one that was published.
 *
 * Nothing on this screen has happened to the reader yet unless it says published. A proposal
 * carries the articles it looked at, the lines it says were missed, the markers it wants to add
 * and what a second model made of them — enough to agree or disagree without taking anyone's
 * word for it.
 */
final class ScanImprovements extends Page
{
    use NeedsShopContext;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'enrichment/scan-improvements';

    protected string $view = 'enrichment::operator.scan-improvements';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::scan.title');
    }

    public function getTitle(): string
    {
        return __('enrichment::scan.title');
    }

    public function getSubheading(): ?string
    {
        return __('enrichment::scan.help');
    }

    private function shopId(): ?string
    {
        return app(TenantContext::class)->id();
    }

    /** @return list<EnrichmentRuleProposal> */
    public function proposals(): array
    {
        $shop = $this->shopId();

        return $shop === null ? [] : app(TenantContext::class)->run($shop, fn (): array => EnrichmentRuleProposal::query()
            ->latest('created_at')
            ->limit(30)
            ->get()
            ->all());
    }

    /** Every version the rules have had, newest first, with who published it. */
    public function versions(): array
    {
        $shop = $this->shopId();

        return $shop === null ? [] : app(TenantContext::class)->run($shop, fn (): array => EnrichmentContentRules::query()
            ->with('creator:id,name')
            ->latest('version')
            ->limit(20)
            ->get()
            ->all());
    }

    /** What the reader is using right now. */
    public function inForce(): array
    {
        $shop = $this->shopId();

        return $shop === null ? [] : app(TenantContext::class)->run($shop, fn (): array => EnrichmentContentRules::inForce($shop));
    }

    public function runAudit(): void
    {
        $shop = $this->shopId();

        if ($shop === null) {
            return;
        }

        $run = app(AuditContentReading::class)->handle($shop);

        Notification::make()->success()->title((string) $run->summary())->send();
    }

    public function publish(string $id): void
    {
        $proposal = $this->find($id);

        if ($proposal === null) {
            return;
        }

        $published = app(PublishContentRules::class)->handle($proposal, Auth::id());

        $published === null
            ? Notification::make()->warning()->title(__('enrichment::scan.not_publishable'))->send()
            : Notification::make()->success()->title(__('enrichment::scan.published', ['version' => $published->version]))->send();
    }

    public function discard(string $id): void
    {
        $proposal = $this->find($id);

        if ($proposal !== null) {
            app(PublishContentRules::class)->discard($proposal, Auth::id());
        }
    }

    private function find(string $id): ?EnrichmentRuleProposal
    {
        $shop = $this->shopId();

        return $shop === null ? null : app(TenantContext::class)->run($shop, fn (): ?EnrichmentRuleProposal => EnrichmentRuleProposal::query()->find($id));
    }
}
