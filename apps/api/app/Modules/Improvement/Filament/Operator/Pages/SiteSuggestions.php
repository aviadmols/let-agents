<?php

namespace App\Modules\Improvement\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Improvement\Actions\DecideProposal;
use App\Modules\Improvement\Actions\RunDailyReview;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * What the daily review proposes for the site, waiting for the team: words shoppers use that the
 * store calls otherwise, questions worth answering on the site, and things shoppers look for that
 * the store does not have. Each shows the searches and questions behind it.
 */
class SiteSuggestions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?int $navigationSort = 17;

    protected static ?string $slug = 'improvement/suggestions';

    protected string $view = 'improvement::operator.suggestions';

    #[Url]
    public ?string $shop = null;

    public static function getNavigationLabel(): string
    {
        return __('improvement::ui.title');
    }

    public function getTitle(): string
    {
        return __('improvement::ui.title');
    }

    public function getSubheading(): ?string
    {
        return __('improvement::ui.subheading');
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** False in the merchant panel, where the shop is the one in the address and cannot change. */
    public function picksShop(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array{pending: Collection<int, ImprovementProposal>, decided: Collection<int, ImprovementProposal>, latest: ImprovementReview|null}|null */
    public function suggestions(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        return app(TenantContext::class)->run($this->shop, fn (): array => [
            'pending' => ImprovementProposal::query()->where('status', ImprovementProposal::PENDING)->latest()->get(),
            'decided' => ImprovementProposal::query()->whereIn('status', [ImprovementProposal::APPLIED, ImprovementProposal::APPROVED, ImprovementProposal::REJECTED])->latest('updated_at')->limit(30)->get(),
            'latest' => ImprovementReview::query()->latest('day')->first(),
        ]);
    }

    public function approve(string $id): void
    {
        $this->decide($id, true);
    }

    public function reject(string $id): void
    {
        $this->decide($id, false);
    }

    /** Runs today's review now, without waiting for the night. */
    public function reviewNow(): void
    {
        if ($this->shop === null) {
            return;
        }

        $run = app(RunDailyReview::class)->handle($this->shop, RunTrigger::Manual);

        $run->status->value === 'succeeded'
            ? Notification::make()->success()->title(__('improvement::ui.reviewed'))->body($run->summary())->send()
            : Notification::make()->danger()->title(__('improvement::ui.review_failed'))->send();
    }

    private function decide(string $id, bool $approve): void
    {
        if ($this->shop === null) {
            return;
        }

        app(TenantContext::class)->run($this->shop, function () use ($id, $approve): void {
            $proposal = ImprovementProposal::query()->find($id);

            if ($proposal !== null) {
                $approve
                    ? app(DecideProposal::class)->approve($proposal, auth()->id())
                    : app(DecideProposal::class)->reject($proposal, auth()->id());
            }
        });

        Notification::make()->success()->title(__($approve ? 'improvement::ui.approved' : 'improvement::ui.rejected'))->send();
    }
}
