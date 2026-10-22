<?php

namespace App\Modules\Shopify\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ShopScan;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A Shopify store installed the app: the store becomes a shop here, with no form and no plugin.
 *
 *   1. install      its tokens, kept encrypted (a store that installs again keeps its shop)
 *   2. shop         a shop on its own address, paused until the subscription is approved
 *   3. connection   a store connection with a key of its own, so the storefront's site key
 *                   never changes when Shopify renews the access token
 *   4. owner        the store's owner as the shop's manager, who opens the panel from Shopify
 *   5. theme        the site key and the API address written on the store, for the app embed,
 *                   and the web pixel on, for purchases that came through the search
 *   6. catalogue    a new shop's first scan, the catalogue first; a sync for a store installing again
 */
final class InstallStore
{
    /** The store's metafields the theme app embed reads. */
    public const METAFIELD_NAMESPACE = 'let_agents';

    private const SHOP_QUERY = <<<'GQL'
        query {
          shop { id name email contactEmail currencyCode ianaTimezone primaryDomain { host } plan { partnerDevelopment } }
          products(first: 25) { nodes { title } }
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
        private readonly ConnectPixel $pixel,
    ) {}

    /**
     * Hebrew when a third or more of the store's name and first titles hold Hebrew ("מברגה Makita DHP" is
     * Hebrew, brand and all), else English: an English store (Shopify's sample products) is searched,
     * answered and read in English. The shop can change it in its profile.
     *
     * @param  list<string>  $texts
     */
    public static function language(array $texts): string
    {
        $texts = array_values(array_filter($texts, fn (string $t): bool => preg_match('/\p{L}/u', $t) === 1));
        $hebrew = count(array_filter($texts, fn (string $t): bool => preg_match('/\p{Hebrew}/u', $t) === 1));

        return $texts !== [] && $hebrew * 3 >= count($texts) ? 'he' : 'en';
    }

    /** @param array<string, mixed> $tokenAnswer what Shopify answered for the access token */
    public function handle(string $shopDomain, array $tokenAnswer): ShopifyInstall
    {
        $install = ShopifyInstall::query()->firstOrNew(['shop_domain' => $shopDomain]);
        $install->forceFill(ShopifyApi::tokenFields($tokenAnswer) + ['installed_at' => now(), 'uninstalled_at' => null]);

        $answer = $this->api->query($install, self::SHOP_QUERY);
        $store = (array) ($answer['shop'] ?? []);
        $host = (string) ($store['primaryDomain']['host'] ?? $shopDomain);
        // the language its products are written in, for a new shop: what the search, the answers and the picture reader write in
        $language = self::language([(string) ($store['name'] ?? ''), ...array_map(fn ($p): string => (string) ($p['title'] ?? ''), (array) ($answer['products']['nodes'] ?? []))]);

        $created = false;

        DB::transaction(function () use ($install, $store, $host, $shopDomain, $language, &$created): void {
            $shop = $install->shop_id !== null ? Shop::query()->find($install->shop_id) : null;
            $created = $shop === null;
            $shop ??= $this->shops->handle([
                'name' => (string) ($store['name'] ?? Str::before($shopDomain, '.')),
                'domain' => $host,
                'platform' => ShopPlatform::Shopify->value,
                'slug' => Str::slug(Str::before($shopDomain, '.myshopify.com')),
                'currency' => (string) ($store['currencyCode'] ?? 'ILS'),
                'timezone' => (string) ($store['ianaTimezone'] ?? 'Asia/Jerusalem'),
                'content_locale' => $language,
            ]);

            // Installed again after asking for the shop to be deleted: it is kept, and the plan can start again.
            if ($shop->leaving()) {
                $shop->forceFill(['deletion_due_at' => null, 'deletion_asked_by' => null])->save();
            }

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
        // Quietly off when the scope is not granted: the store still works, without purchase counts.
        $this->pixel->handle($install);

        // A new shop's whole first scan starts now, the catalogue its first step, so the panel opens on it
        // under way; a store installing again gets the sync alone (its scans are the night's and its manager's).
        if (! $created || ! $this->firstScan($install->shop)) {
            Artisan::queue('catalog:sync', ['shop' => $install->shop->slug])->onQueue('long');
        }

        return $install;
    }

    /** The store's first scan (Admin\Contracts\ShopScan), started once; false when it did not start. */
    private function firstScan(Shop $shop): bool
    {
        try {
            return app()->bound(ShopScan::class) && app(ShopScan::class)->start($shop);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
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
        $this->manager($install);
    }

    /**
     * Who Shopify signs in when the app opens: the store's owner. When the owner's email is an
     * operator's, the store gets an account of its own (owner@{store}.myshopify.com, this shop only):
     * Shopify vouches for whoever manages the store, never for an operator's whole panel.
     */
    public function manager(ShopifyInstall $install): ?User
    {
        $shop = $install->shop;

        if ($shop === null) {
            return null;
        }

        $owner = User::query()->where('is_operator', false)
            ->whereHas('shops', fn ($q) => $q->where('shops.id', $shop->id)->where('shop_user.role', ShopRole::Owner->value))
            ->first();

        if ($owner !== null || $install->owner_email === null || ! User::query()->where('email', $install->owner_email)->where('is_operator', true)->exists()) {
            return $owner;
        }

        $own = User::query()->firstOrCreate(['email' => 'owner@'.$install->shop_domain], [
            'name' => $shop->name,
            'password' => Str::random(40),
        ]);
        $own->attachShop($shop, ShopRole::Owner);

        return $own;
    }

    /** The site key and the API address, on the store, where the app embed reads them. */
    private function writeThemeSettings(ShopifyInstall $install): void
    {
        $connection = $this->tenant->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $install->shop_id)->firstOrFail());
        $shopId = (string) ($this->api->query($install, 'query { shop { id } }')['shop']['id'] ?? '');

        $answer = $this->api->query($install, self::METAFIELDS, ['metafields' => [
            ['ownerId' => $shopId, 'namespace' => self::METAFIELD_NAMESPACE, 'key' => 'site_key', 'type' => 'single_line_text_field', 'value' => (string) $connection->site_key],
            ['ownerId' => $shopId, 'namespace' => self::METAFIELD_NAMESPACE, 'key' => 'api', 'type' => 'single_line_text_field', 'value' => rtrim((string) config('app.url'), '/').'/api/v1'],
        ]]);

        // Without them the app embed prints nothing and the store keeps its own search: the operator hears of it.
        if (($answer['metafieldsSet']['userErrors'] ?? []) !== []) {
            Log::warning('shopify.metafields.not_set', ['shop' => $install->shop_domain, 'errors' => $answer['metafieldsSet']['userErrors']]);
        }
    }
}
