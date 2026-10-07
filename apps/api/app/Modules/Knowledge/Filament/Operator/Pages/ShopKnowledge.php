<?php

namespace App\Modules\Knowledge\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Knowledge\Models\KnowledgeSnapshot;
use App\Modules\Knowledge\Support\Census;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Everything the system knows about this shop, where it came from, and what is still missing.
 *
 * Seven layers, in the order they happen: the shop shared it, code read it, a model added to it,
 * a person decided on it, code computed from it, rules shaped the reading, shoppers asked about
 * it. Every screen in the panel shows a slice of one of those. None of them shows the whole, and
 * nothing at all shows what is *not* there, which is the half a shop can act on.
 */
final class ShopKnowledge extends Page
{
    use NeedsShopContext;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'shops';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'knowledge';

    protected string $view = 'knowledge::operator.shop-knowledge';

    public static function getNavigationLabel(): string
    {
        return __('knowledge::ui.title');
    }

    public function getTitle(): string
    {
        return __('knowledge::ui.title');
    }

    public function getSubheading(): ?string
    {
        return __('knowledge::ui.help');
    }

    public function shop(): ?Shop
    {
        $id = app(TenantContext::class)->id();

        return $id === null ? null : app(TenantContext::class)->runUnscoped(fn () => Shop::query()->find($id));
    }

    /**
     * What is true right now, counted the same way the night counts it.
     *
     * @return array<string, mixed>
     */
    public function now(): array
    {
        $shopId = app(TenantContext::class)->id();

        if ($shopId === null) {
            return [];
        }

        $coverage = Census::coverage();

        return [
            'coverage' => $coverage,
            'gaps' => Census::gaps($coverage),
            'freshness' => Census::freshness($shopId),
            'signals' => Census::signals(),
            'arrangement' => Census::arrangement(),
        ];
    }

    /**
     * The same counts a week ago, so a number can be read as a direction and not only a size.
     *
     * @return array<string, mixed>|null
     */
    public function weekAgo(): ?array
    {
        $shopId = app(TenantContext::class)->id();

        if ($shopId === null) {
            return null;
        }

        [, $earlier] = KnowledgeSnapshot::latestAnd($shopId, 6);

        return $earlier?->coverage;
    }

    /** How old a reading is allowed to be before the screen says so. */
    public function staleAfterDays(): int
    {
        return 7;
    }

    /** The steps whose freshness is worth showing, in the order they happen. */
    public function steps(): array
    {
        return Census::STEPS;
    }
}
