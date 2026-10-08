<?php

namespace App\Modules\Recovery\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Recovery\Models\RecoveryCart;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

/**
 * Shoppers who left an email before payment, and what became of their cart: paid later, still
 * waiting, or not paid with a short report on what they looked for and what to send them. The
 * popup is switched on and off here, by the operator or the shop.
 */
class AbandonedCarts extends Page
{
    use NeedsShopContext;
    use WithPagination;

    public const FILTERS = ['all', 'reported', 'open', 'converted'];

    private const PER_PAGE = 30;

    private const DAYS = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'recovery/carts';

    protected string $view = 'recovery::operator.carts';

    public ?string $shop = null;

    #[Url]
    public string $filter = 'all';

    public static function getNavigationLabel(): string
    {
        return __('recovery::ui.title');
    }

    public function getTitle(): string
    {
        return __('recovery::ui.title');
    }

    public function getSubheading(): ?string
    {
        return __('recovery::ui.subheading');
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /** Model checks and costs are for the operator only. */
    public function operatorView(): bool
    {
        return Filament::getCurrentPanel()?->getId() === User::OPERATOR_PANEL;
    }

    public function on(): bool
    {
        return $this->shop !== null && Features::enabled('recovery.capture', $this->shop);
    }

    public function setOn(bool $on): void
    {
        abort_unless($this->shop !== null, 403);

        Features::override('recovery.capture', $on, $this->shop);
        Notification::make()->success()->title(__('recovery::ui.'.($on ? 'turned_on' : 'turned_off')))->send();
    }

    /** @return array{captured: int, converted: int, reported: int} the last 30 days */
    public function tiles(): array
    {
        return app(TenantContext::class)->run((string) $this->shop, function (): array {
            $since = now()->subDays(self::DAYS);

            return [
                'captured' => RecoveryCart::query()->where('captured_at', '>=', $since)->count(),
                'converted' => RecoveryCart::query()->where('captured_at', '>=', $since)->where('status', RecoveryCart::CONVERTED)->count(),
                'reported' => RecoveryCart::query()->where('captured_at', '>=', $since)->where('status', RecoveryCart::REPORTED)->count(),
            ];
        });
    }

    /** @return LengthAwarePaginator<int, RecoveryCart> */
    public function carts(): LengthAwarePaginator
    {
        return app(TenantContext::class)->run((string) $this->shop, fn () => RecoveryCart::query()
            ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))
            ->latest('captured_at')
            ->paginate(self::PER_PAGE));
    }

    /**
     * Product names for the carts on the page, by external id.
     *
     * @param  iterable<RecoveryCart>  $carts
     * @return array<string, string>
     */
    public function names(iterable $carts): array
    {
        $ids = [];
        foreach ($carts as $cart) {
            array_push($ids, ...array_column($cart->items, 'product_id'));
        }

        return app(TenantContext::class)->run((string) $this->shop, fn (): array => CatalogProduct::query()
            ->whereIn('external_id', array_unique($ids))->pluck('title', 'external_id')->map(fn ($t): string => (string) $t)->all());
    }

    /** When a waiting cart gets its report, if it is not paid by then. */
    public function reportAt(RecoveryCart $cart): string
    {
        return $cart->captured_at->copy()->addMinutes((int) Settings::get('recovery.wait_minutes', $this->shop))->timezone('Asia/Jerusalem')->format('d/m H:i');
    }
}
