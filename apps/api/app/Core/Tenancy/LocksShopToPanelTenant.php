<?php

namespace App\Core\Tenancy;

use Filament\Facades\Filament;

/**
 * For an admin screen that serves one shop at a time: the shop is the panel's tenant and nothing
 * else can change it.
 *
 * The screens these pages extend keep the shop in a public, URL-bound property so an operator can
 * switch shops. In a tenant panel that property is an open door: Livewire hydrates it from the
 * request on every update, so a shop id put into the address would be read back and used. Setting
 * it once on mount is not enough — mount runs on the first load only.
 *
 * So the shop is written back from the tenant at every point the property could have changed: when
 * the component is hydrated, when it boots, on mount, and if anything assigns it mid-request.
 * Filament has already checked that this user may open this tenant.
 */
trait LocksShopToPanelTenant
{
    /**
     * A screen inherited from the operator panel may refuse to open until a shop is chosen. Here
     * the shop is the address, so it is always chosen and the screen is always there.
     */
    public static function canAccess(...$arguments): bool
    {
        return Filament::getTenant() !== null;
    }

    /** In the menu once the shop is known, unless the screen took itself off the menu. */
    public static function shouldRegisterNavigation(...$arguments): bool
    {
        return static::$shouldRegisterNavigation && Filament::getTenant() !== null;
    }

    /**
     * A screen inherited from the operator panel may take its shop from the tenant context. Here
     * the panel's own tenant is the authority, and it is right even before any middleware ran.
     */
    protected function takeShopFromPanel(): void
    {
        $this->lockShopToTenant();
    }

    public function mount(): void
    {
        $this->lockShopToTenant();
    }

    public function hydrate(): void
    {
        $this->lockShopToTenant();
    }

    public function booted(): void
    {
        $this->lockShopToTenant();
    }

    public function updatedShop(): void
    {
        $this->lockShopToTenant();
    }

    /** The shop is never a choice on these screens. */
    public function picksShop(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        $shop = Filament::getTenant();

        return $shop === null ? [] : [(string) $shop->getKey() => (string) $shop->name];
    }

    private function lockShopToTenant(): void
    {
        $this->shop = (string) (Filament::getTenant()?->getKey() ?? '');
    }
}
