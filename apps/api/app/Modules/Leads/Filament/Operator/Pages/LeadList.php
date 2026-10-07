<?php

namespace App\Modules\Leads\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Models\Lead;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The people who asked to be contacted.
 *
 * Shown masked. A phone number is the most personal thing this platform holds, and a screen that
 * puts fifty of them in front of whoever opens the tab treats them as a list rather than as
 * people. Revealing one is a deliberate act, and it is recorded — not to catch anybody out, but
 * because a shop that can say who looked at what can answer a question a customer is entitled to
 * ask.
 *
 * The quality figure is arithmetic: how much of the flow they filled in, whether they went past
 * what was required, and whether they had asked anything first. It is never a model's impression
 * of a person.
 */
class LeadList extends Page
{
    use NeedsShopContext;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'leads';

    protected string $view = 'leads::operator.list';

    #[Url]
    public string $show = 'new';

    /** Which leads have been revealed in this sitting, by id. */
    public array $revealed = [];

    public static function getNavigationLabel(): string
    {
        return __('leads::list.title');
    }

    public function getTitle(): string
    {
        return __('leads::list.title');
    }

    public function getSubheading(): ?string
    {
        return __('leads::list.help');
    }

    /** @return Collection<int, Lead> */
    public function leads(): Collection
    {
        $shopId = app(TenantContext::class)->id();

        if ($shopId === null) {
            return collect();
        }

        return app(TenantContext::class)->run($shopId, fn (): Collection => Lead::query()
            ->when($this->show !== 'all', fn ($q) => $q->where('status', $this->show))
            ->latest('created_at')
            ->limit(100)
            ->get());
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $shopId = app(TenantContext::class)->id();

        if ($shopId === null) {
            return [];
        }

        return app(TenantContext::class)->run($shopId, fn (): array => Lead::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all());
    }

    /** Looking at somebody's phone number is a deliberate act, so it is one, and it is recorded. */
    public function reveal(string $id): void
    {
        $shopId = app(TenantContext::class)->id();

        if ($shopId === null) {
            return;
        }

        app(TenantContext::class)->run($shopId, function () use ($id): void {
            $lead = Lead::query()->find($id);

            if ($lead === null) {
                return;
            }

            $lead->forceFill(['seen_by' => Auth::id(), 'seen_at' => $lead->seen_at ?? now()])->save();
        });

        $this->revealed[$id] = true;
    }

    public function contactOf(Lead $lead): string
    {
        return isset($this->revealed[$lead->id]) ? $lead->contact : $lead->contact_masked;
    }

    public function mark(string $id, string $status): void
    {
        if (! in_array($status, [Lead::NEW, Lead::HANDLED, Lead::DISCARDED], true)) {
            return;
        }

        $shopId = app(TenantContext::class)->id();

        if ($shopId === null) {
            return;
        }

        app(TenantContext::class)->run($shopId, fn () => Lead::query()->whereKey($id)->update(['status' => $status]));

        Notification::make()->success()->title(__('leads::list.marked.'.$status))->send();
    }

    public function setShow(string $show): void
    {
        $this->show = in_array($show, [Lead::NEW, Lead::HANDLED, Lead::DISCARDED, 'all'], true) ? $show : 'new';
    }
}
