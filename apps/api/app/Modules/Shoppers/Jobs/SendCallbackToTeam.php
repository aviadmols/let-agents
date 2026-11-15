<?php

namespace App\Modules\Shoppers\Jobs;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shoppers\Mail\CallbackReceivedMail;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * A shopper who asked to be called back (they left a phone or an email under an answer, or asked for a person), mailed
 * to the store's team: its owners and members, each in their panel's language, with what they asked and the page. Until
 * now it waited only on the Shoppers screen (Aviad, 15.11). Off where the shop turns it off (shoppers.email_team_callbacks).
 */
final class SendCallbackToTeam implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** The panel's Shoppers screen, where "Waiting for you" lists them. */
    private const SHOPPERS_ROUTE = 'filament.merchant.pages.shoppers';

    public function __construct(public readonly string $shopId, public readonly int $callbackId) {}

    public function handle(TenantContext $tenant): void
    {
        $shop = Shop::query()->find($this->shopId);

        if ($shop === null || ! Settings::get('shoppers.email_team_callbacks', $this->shopId)) {
            return;
        }

        $tenant->run($this->shopId, function () use ($shop): void {
            $callback = ShopperCallback::query()->find($this->callbackId);
            $identity = $callback === null ? null : ShopperIdentity::query()->find($callback->identity_id);

            if ($callback === null || $identity === null) {
                return;
            }

            $to = $shop->belongsToMany(User::class, 'shop_user')->wherePivotIn('role', [ShopRole::Owner->value, ShopRole::Member->value])->get()
                ->mapWithKeys(fn (User $u): array => [mb_strtolower((string) $u->email) => $u->preferredLocale()]);

            foreach ($to as $email => $locale) {
                $locale = in_array($locale, ['he', 'en'], true) ? $locale : 'he';

                try {
                    Mail::to($email)->locale($locale)->send($this->mail($shop, $callback, $identity, $locale));
                } catch (Throwable $e) {
                    Log::warning('A callback email was not sent', ['shop' => $shop->id, 'error' => $e->getMessage()]);
                }
            }
        });
    }

    private function mail(Shop $shop, ShopperCallback $callback, ShopperIdentity $identity, string $locale): CallbackReceivedMail
    {
        $page = match ($callback->page_type) {
            'product' => CatalogProduct::query()->where('external_id', $callback->page_id)->first(['title', 'url']),
            'content' => CatalogContent::query()->where('external_id', $callback->page_id)->first(['title', 'url']),
            default => null,
        };

        return new CallbackReceivedMail(
            shopName: (string) $shop->name,
            channel: $identity->channel === 'email' ? 'email' : 'phone',
            contact: (string) $identity->contact,
            name: $callback->name,
            question: (string) $callback->question,
            pageTitle: $page?->title,
            pageUrl: $page?->url,
            at: $callback->created_at->copy()->timezone((string) config('app.display_timezone', 'Asia/Jerusalem'))->locale($locale)->translatedFormat('j.n.Y H:i'),
            panelUrl: Route::has(self::SHOPPERS_ROUTE) ? route(self::SHOPPERS_ROUTE, ['tenant' => $shop->slug]) : null,
        );
    }
}
