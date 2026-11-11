<?php

namespace App\Modules\Shopify\Feed;

use App\Modules\Connections\Contracts\PlatformEditLinks;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Models\ShopifyInstall;

/**
 * Where a Shopify store owner edits a page, an article or the store's policies in their admin. The feed keeps a
 * page's and an article's numeric id, and a policy as "policy-…", which Shopify edits on one screen.
 */
final class ShopifyEditLinks implements PlatformEditLinks
{
    public function platform(): string
    {
        return 'shopify';
    }

    public function editUrl(StoreConnection $connection, string $type, string $externalId): ?string
    {
        $domain = ShopifyInstall::query()->where('shop_id', (string) $connection->shop_id)->whereNull('uninstalled_at')->latest('installed_at')->value('shop_domain');
        $store = preg_replace('/\.myshopify\.com$/', '', (string) $domain);

        if ($store === '' || $store === null) {
            return null;
        }

        $admin = 'https://admin.shopify.com/store/'.$store;

        if (str_starts_with($externalId, 'policy-')) {
            return $admin.'/settings/legal';
        }

        if (preg_match('/^\d+$/', $externalId) !== 1) {
            return null;
        }

        return match ($type) {
            'page' => $admin.'/content/pages/'.$externalId,
            'post' => $admin.'/content/articles/'.$externalId,
            default => null,
        };
    }
}
