<?php

namespace App\Modules\Search\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\FirstStep;
use App\Modules\Admin\Contracts\FirstSteps;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Filament\Merchant\Pages\SearchDisplay;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Two of the shop's first steps, the search's own:
 *
 *   on     Lets is live in the store. The store decides that (the plugin's mode, the Shopify app embed),
 *          so it is read from what shoppers did: in preview no shopper sees Lets, so shoppers who saw or
 *          opened it, or several of them searching, mean it is live.
 *   look   the merchant saw it as a shopper: the store with the preview link, in a new tab. Done when
 *          they opened it from here (the home marks it), or once the store is live.
 */
final class SearchFirstSteps implements FirstSteps
{
    /** Different sessions searching in two weeks that mean real shoppers, not the team in preview. */
    private const LIVE_SESSIONS = 3;

    public const LOOK_DONE = 'step.look';

    public function steps(Shop $shop): array
    {
        $live = $this->live();
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $shop->id)->first());
        $site = $connection?->site_url ?: ($shop->domain ? 'https://'.$shop->domain : null);
        $preview = $site === null ? null : rtrim($site, '/').'/'.($connection !== null && ! $live ? '?let_agents_preview='.$connection->previewKey() : '');
        $looked = DB::table('shop_notices_done')->where('shop_id', $shop->id)->where('key', self::LOOK_DONE)->exists();

        return [
            new FirstStep(
                key: 'on',
                order: 10,
                title: __('search::home.step.on.title'),
                body: __($shop->platform === ShopPlatform::Shopify ? 'search::home.step.on.body_shopify' : 'search::home.step.on.body_woocommerce'),
                button: __('search::home.step.on.button'),
                url: SearchDisplay::getUrl(panel: 'merchant', tenant: $shop),
                done: $live,
            ),
            new FirstStep(
                key: 'look',
                order: 20,
                title: __('search::home.step.look.title'),
                body: __('search::home.step.look.body'),
                button: __('search::home.step.look.button'),
                url: $preview,
                done: $looked || $live,
                external: true,
            ),
        ];
    }

    private function live(): bool
    {
        $since = now()->subDays(14);

        return AnalyticsEvent::query()->where('preview', false)->whereIn('type', ['exposure', 'open', 'click'])->where('occurred_at', '>=', $since)->exists()
            || SearchLog::query()->where('created_at', '>=', $since)->whereNotNull('session_id')->distinct()->count('session_id') >= self::LIVE_SESSIONS;
    }
}
