<?php

namespace App\Modules\Admin\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Actions\CreateDesignPass;
use App\Modules\Admin\Models\DesignPass;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Links for a designer (CreateDesignPass): one shop's panel for a day, with its real content and everything a team
 * member may do, and the panel's stylesheet loaded from the designer's own computer. The link is shown once; the open
 * ones can be closed at any time.
 */
final class DesignLinks extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'design-links';

    protected string $view = 'admin::operator.design-links';

    public ?string $shop = null;

    /** The link just made: shown once. */
    public ?string $made = null;

    public static function getNavigationLabel(): string
    {
        return __('admin::design.title');
    }

    public function getTitle(): string
    {
        return __('admin::design.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::design.subheading');
    }

    /** @return array<string, string> shop id => name */
    public function shops(): array
    {
        return app(TenantContext::class)->runUnscoped(fn () => Shop::query()->orderBy('name')->pluck('name', 'id')->all());
    }

    /** @return list<array{id: string, shop: string, expires: string, used: string|null}> the open passes, the latest first */
    public function open(): array
    {
        return DesignPass::query()->with('shop:id,name')->whereNull('revoked_at')->where('expires_at', '>', now())->latest('created_at')->get()
            ->map(fn (DesignPass $p): array => [
                'id' => (string) $p->id,
                'shop' => (string) $p->shop?->name,
                'expires' => $p->expires_at->timezone('Asia/Jerusalem')->format('d/m H:i'),
                'used' => $p->last_used_at?->timezone('Asia/Jerusalem')->format('d/m H:i'),
            ])->all();
    }

    public function makeLink(): void
    {
        $shop = $this->shop === null ? null : app(TenantContext::class)->runUnscoped(fn () => Shop::query()->find($this->shop));
        if (! $shop instanceof Shop) {
            return;
        }
        $made = app(CreateDesignPass::class)->handle($shop, auth()->id());
        $this->made = route('admin.design', ['token' => $made['token']]);
    }

    public function revoke(string $id): void
    {
        DesignPass::query()->whereKey($id)->update(['revoked_at' => now()]);
    }
}
