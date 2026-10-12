<?php

namespace App\Modules\Shopify\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns on the app's web pixel on a store (extensions/shopify/extensions/let-agents-pixel), with the
 * store's site key and the API address, so purchases that came through the search are reported.
 * Shopify sends us no orders otherwise.
 *
 * Needs the write_pixels scope: a store that installed before it was asked for gets it when the
 * merchant approves the app's new scopes; until then this fails quietly and is tried again the
 * next time the app is opened. Done once per store: the same pixel is updated, never doubled.
 */
final class ConnectPixel
{
    private const CREATE = <<<'GQL'
        mutation ($settings: JSON!) {
          webPixelCreate(webPixel: { settings: $settings }) { webPixel { id } userErrors { field message code } }
        }
        GQL;

    private const FIND = 'query { webPixel { id } }';

    private const UPDATE = <<<'GQL'
        mutation ($id: ID!, $settings: JSON!) {
          webPixelUpdate(id: $id, webPixel: { settings: $settings }) { webPixel { id } userErrors { field message code } }
        }
        GQL;

    public function __construct(
        private readonly ShopifyApi $api,
        private readonly TenantContext $tenant,
    ) {}

    /** Whether the pixel is on, with this store's settings. */
    public function handle(ShopifyInstall $install): bool
    {
        $siteKey = $this->tenant->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $install->shop_id)->value('site_key'));

        if (! is_string($siteKey) || $siteKey === '') {
            return false;
        }

        $settings = (string) json_encode(['siteKey' => $siteKey, 'api' => rtrim((string) config('app.url'), '/').'/api/v1']);

        try {
            $created = (array) ($this->api->query($install, self::CREATE, ['settings' => $settings])['webPixelCreate'] ?? []);

            if (($created['webPixel']['id'] ?? null) === null) {
                // The store has the pixel already (TAKEN): the same one gets these settings.
                $id = (string) ($this->api->query($install, self::FIND)['webPixel']['id'] ?? '');
                $updated = $id === '' ? [] : (array) ($this->api->query($install, self::UPDATE, ['id' => $id, 'settings' => $settings])['webPixelUpdate'] ?? []);

                if (($updated['webPixel']['id'] ?? null) === null) {
                    Log::info('shopify.pixel.not_connected', ['shop' => $install->shop_domain, 'errors' => $created['userErrors'] ?? null]);

                    return false;
                }
            }
        } catch (Throwable $e) {
            // Most often the scope is not granted yet; the next open tries again.
            Log::info('shopify.pixel.not_connected', ['shop' => $install->shop_domain, 'error' => $e->getMessage()]);

            return false;
        }

        Cache::forever(self::doneKey($install), true);

        return true;
    }

    /** On opening the app: once, until it worked. */
    public function once(ShopifyInstall $install): void
    {
        if (! Cache::has(self::doneKey($install))) {
            $this->handle($install);
        }
    }

    private static function doneKey(ShopifyInstall $install): string
    {
        return 'shopify:pixel:'.$install->shop_domain;
    }
}
