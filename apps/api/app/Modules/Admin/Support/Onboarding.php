<?php

namespace App\Modules\Admin\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ConnectionFacts;
use App\Modules\Admin\Contracts\ShopFacts;
use App\Modules\Admin\Contracts\ShopScan;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Throwable;

/**
 * What the first-run screens read, gathered from the modules that answer (ShopFacts, ConnectionFacts,
 * ShopScan), each asked inside the shop; a module that fails is left out and reported, never the page.
 * And who lands on them: the owner or a member of a shop not set up yet, never a viewer (who cannot set
 * it up) nor an operator (who opens a store to look at it).
 */
final class Onboarding
{
    public function __construct(private readonly TenantContext $tenant) {}

    public static function lands(Shop $shop, mixed $user): bool
    {
        return $shop->onboarded_at === null && $user instanceof User && ! $user->is_operator && $user->canEditShop($shop);
    }

    /** @return array<string, mixed> what the modules counted (ShopFacts keys) */
    public function facts(Shop $shop): array
    {
        return $this->merged(ShopFacts::TAG, $shop, fn (ShopFacts $source): array => $source->shopFacts($shop));
    }

    /** @return array<string, mixed> how the store is connected (ConnectionFacts keys) */
    public function connection(Shop $shop): array
    {
        return $this->merged(ConnectionFacts::TAG, $shop, fn (ConnectionFacts $source): array => $source->facts($shop));
    }

    public function scan(): ?ShopScan
    {
        return app()->bound(ShopScan::class) ? app(ShopScan::class) : null;
    }

    /**
     * @param  callable(object): array<string, mixed>  $ask
     * @return array<string, mixed>
     */
    private function merged(string $tag, Shop $shop, callable $ask): array
    {
        $facts = [];

        foreach (app()->tagged($tag) as $source) {
            try {
                $facts = array_merge($facts, array_filter($this->tenant->run((string) $shop->id, fn (): array => $ask($source)), fn ($v): bool => $v !== null));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $facts;
    }
}
