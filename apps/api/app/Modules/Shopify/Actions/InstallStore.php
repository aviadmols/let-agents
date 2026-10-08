<?php

namespace App\Modules\Shopify\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApi;
use App\Modules\Tenancy\Contracts\CreatesShops;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A Shopify store installed the app: the store becomes a shop here, with no form and no plugin.
 *
 *   1. install      its tokens, kept encrypted (a store that installs again keeps its shop)
 *   2. shop         a shop on its own address, paused until the subscription is approved
 *   3. connection   a store connection with a key of its own, so the storefront's site key
 *                   never changes when Shopify renews the access token
 *   4. owner        the store's owner as the shop's manager, who opens the panel from Shopify
 *   5. theme        the site key and the API address written on the store, for the app embed
 *   6. catalogue    a first sync, on the long queue
 */
final class InstallStore
{
    /** The store's metafields the theme app embed reads. */
    public const METAFIELD_NAMESPACE = 'let_agents';

    private const SHOP_QUERY = <<<'GQL'
        query {
          shop { id name email contactEmail currencyCode ianaTimezone primaryDomain { host } plan { partnerDevelopment } }
        }
        GQL;

    private const METAFIELDS = <<<'GQL'
        mutation ($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) { userErrors { field message } }
        }
        GQL;

    public function __construct(
        private readonly ShopifyApi $api,
        private readonly CreatesShops $shops,
        private readonly TenantContext $tenant,
    ) {}

    /** @param array<string, mixed> $tokenAnswer what Shopify answered for the access token */
    public function handle(string $shopDomain, array $tokenAnswer): ShopifyInstall
    {
        $install = ShopifyInstall::query()->firstOrNew(['shop_domain' => $shopDomain]);
        $install->forceFill(ShopifyApi::tokenFields($tokenAnswer) + ['installed_at' => now(), 'uninstalled_at' => null]);

        $store = (array) ($this->api->query($install, self::SHOP_QUERY)['shop'] ?? []);
        $host = (string) ($store['primaryDomain']['host'] ?? $shopDomain);

        $created = false;

        DB::transaction(function () use ($install, $store, $host, $shopDomain, &$created): void {
            $shop = $install->shop_id !== null ? Shop::query()->find($install->shop_id) : null;
            $created = $shop === null;
            $shop ??= $this->shops->handle([
                'name' => (string) ($store['name'] ?? Str::before($shopDomain, '.')),
                'domain' => $host,
                'platform' => ShopPlatform::Shopify->value,
                'slug' => Str::slug(Str::before($shopDomain, '.myshopify.com')),
                'currency' => (string) ($store['currencyCode'] ?? 'ILS'),
                'timezone' => (string) ($store['ianaTimezone'] ?? 'Asia/Jerusalem'),
            ]);

            // Paused until the merchant approves the subscription: the storefront stays quiet.
            if (! $install->subscribed()) {
                $shop->forceFill(['status' => ShopStatus::Paused])->save();
            }

            $install->forceFill([
                'shop_id' => $shop->id,
                'owner_email' => mb_strtolower((string) ($store['email'] ?? $store['contactEmail'] ?? '')) ?: null,
            ])->save();

            $this->tenant->runUnscoped(function () use ($shop, $host, $shopDomain): void {
                $connection = StoreConnection::query()->firstOrNew(['shop_id' => $shop->id]);

                if (! $connection->exists) {
                    $connection->fill(['platform' => 'shopify', 'access_token' => 'lat_'.Str::random(48)]);
                }

                $connection->site_url = 'https://'.$host;
                // The storefront answers at the myshopify address too, and the search runs there.
                $connection->site_info = array_merge((array) $connection->site_info, ['hosts' => [$shopDomain]]);
                $connection->status = ConnectionStatus::Connected;
                $connection->save();
            });

            $this->owner($install, $shop);
        });

        // Photo search is part of what a Shopify store gets, from the first day: its pictures are
        // scanned as soon as the catalog is in, with no one to turn it on.
        if ($created) {
            Features::override('search.photos', true, (string) $install->shop_id);
            Features::override('retrieval.image_index', true, (string) $install->shop_id);
        }

        $this->writeThemeSettings($install->refresh());
        Artisan::queue('catalog:sync', ['shop' => $install->shop->slug])->onQueue('long');

        return $install;
    }

    /** The store's owner manages the shop here; an operator with the same email stays an operator. */
    private function owner(ShopifyInstall $install, Shop $shop): void
    {
        if ($install->owner_email === null) {
            return;
        }

        $user = User::query()->firstOrCreate(['email' => $install->owner_email], [
            'name' => $shop->name,
            'password' => Str::random(40),
        ]);
        $user->attachShop($shop, ShopRole::Owner);
    }

    /** The site key and the API address, on the store, where the app embed reads them. */
    private function writeThemeSettings(ShopifyInstall $install): void
    {
        $connection = $this->tenant->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $install->shop_id)->firstOrFail());
        $shopId = (string) ($this->api->query($install, 'query { shop { id } }')['shop']['id'] ?? '');

        $this->api->query($install, self::METAFIELDS, ['metafields' => [
            ['ownerId' => $shopId, 'namespace' => self::METAFIELD_NAMESPACE, 'key' => 'site_key', 'type' => 'single_line_text_field', 'value' => (string) $connection->site_key],
            ['ownerId' => $shopId, 'namespace' => self::METAFIELD_NAMESPACE, 'key' => 'api', 'type' => 'single_line_text_field', 'value' => rtrim((string) config('app.url'), '/').'/api/v1'],
        ]]);
    }
}
