<?php

namespace App\Modules\Enrichment\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Actions\RereadProducts;
use App\Modules\Enrichment\Jobs\RereadProductsJob;
use App\Modules\Enrichment\Scanning\FactGaps;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Details most products of a kind have and a few do not: 37 of 41 drills say what is in the box,
 * these 4 say nothing. Each missing product shows what its own text says of it, and the operator
 * sends them to be read again by the model.
 */
final class ProductGaps extends Page
{
    use NeedsShopContext;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 95;

    protected static ?string $slug = 'enrichment/product-gaps';

    protected string $view = 'enrichment::operator.product-gaps';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::gaps.title');
    }

    public function getTitle(): string
    {
        return __('enrichment::gaps.title');
    }

    public function getSubheading(): ?string
    {
        return __('enrichment::gaps.help');
    }

    /** @return list<array<string, mixed>> */
    public function gaps(): array
    {
        $shop = app(TenantContext::class)->id();

        return $shop === null ? [] : app(TenantContext::class)->run($shop, fn (): array => FactGaps::of());
    }

    /** The products missing one detail of one kind, sent to be read again. */
    public function reread(string $type, string $attribute): void
    {
        $shop = app(TenantContext::class)->id();

        if ($shop === null) {
            return;
        }

        $gap = collect($this->gaps())->first(fn (array $g): bool => $g['type'] === $type && $g['attribute'] === $attribute);

        if ($gap === null || $gap['vocabulary_id'] === null || $gap['missing'] === []) {
            return;
        }

        $ids = array_slice(array_column($gap['missing'], 'id'), 0, RereadProducts::MAX);
        RereadProductsJob::dispatch($shop, (string) $gap['vocabulary_id'], $ids);
        Notification::make()->success()->title(__('enrichment::gaps.sent', ['count' => (string) count($ids)]))->send();
    }
}
