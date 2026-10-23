<?php

namespace App\Modules\Shopify\Actions;

use App\Core\Facades\Settings;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApi;
use App\Modules\Shopify\Support\ShopifyApps;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * The app's monthly plan, billed by Shopify on the merchant's Shopify invoice every 30 days, after
 * shopify.trial_days: the plan the merchant chose (Plan: its name on the bill and its price), or
 * shopify.plan_name at shopify.plan_price_usd when none is offered. A development store is billed
 * in test mode, so a test install never charges anyone. The active subscription's name says the
 * shop's plan again: an upgrade holds from its approval, a downgrade from the next period (Shopify).
 * The trial is one per store: a plan changed, or the app installed again, carries the days left of it, and none once
 * it ended or a plan was paid without one (trialDaysLeft).
 *
 * The shop is active while the subscription is, and paused otherwise: the storefront goes quiet
 * and nothing is lost. A plan the owner cancels is not billed again, and the shop keeps working until the end of the
 * period already paid (or of its free trial): Shop::plan_ends_at, after which EndDuePlans pauses it. Removing the app
 * ends it at once. While shopify.charge is off nobody is billed: a store without a subscription
 * is marked free and active, and is asked to approve the plan once charging is turned on.
 */
final class ManageSubscription
{
    private const CREATE = <<<'GQL'
        mutation ($name: String!, $returnUrl: URL!, $trialDays: Int, $test: Boolean, $lineItems: [AppSubscriptionLineItemInput!]!) {
          appSubscriptionCreate(name: $name, returnUrl: $returnUrl, trialDays: $trialDays, test: $test, lineItems: $lineItems) {
            appSubscription { id status }
            confirmationUrl
            userErrors { field message }
          }
        }
        GQL;

    private const ACTIVE = <<<'GQL'
        query {
          currentAppInstallation { activeSubscriptions { id name status trialDays createdAt currentPeriodEnd } }
          shop { plan { partnerDevelopment } }
        }
        GQL;

    private const CANCEL = <<<'GQL'
        mutation ($id: ID!, $prorate: Boolean) {
          appSubscriptionCancel(id: $id, prorate: $prorate) {
            appSubscription { id status }
            userErrors { field message }
          }
        }
        GQL;

    public function __construct(private readonly ShopifyApi $api) {}

    /**
     * The owner ended the plan: Shopify cancels the subscription now (no credit back for the days left, prorate
     * false), so it is never billed again, and the shop keeps working to the end of the period already paid.
     * That end is read before cancelling (Shopify gives it only while the subscription is active) and kept on the
     * shop. A store charged nothing (free) has none: it pauses at once.
     *
     * @return CarbonInterface|null when the service paid for ends; null: it ends now
     */
    public function cancel(ShopifyInstall $install): ?CarbonInterface
    {
        $ends = null;

        if ($install->subscription_id !== null && $install->subscription_id !== '') {
            $ends = $install->subscription_status === ShopifyInstall::ACTIVE ? $this->paidUntil($install) : null;
            $answer = (array) ($this->api->query($install, self::CANCEL, ['id' => $install->subscription_id, 'prorate' => false])['appSubscriptionCancel'] ?? []);

            if (($answer['userErrors'] ?? []) !== []) {
                throw new RuntimeException('Shopify refused to cancel the subscription: '.json_encode($answer['userErrors']));
            }
        }

        $install->forceFill(['subscription_status' => 'cancelled'])->save();

        if ($ends !== null) {
            Shop::query()->find($install->shop_id)?->forceFill(['plan_ends_at' => $ends])->save();
        }

        $this->follow($install);

        return $ends;
    }

    /**
     * When the period the store already paid for ends: its free trial's end while it is in one (nothing was billed,
     * the trial runs out), otherwise the active subscription's currentPeriodEnd. Null when neither is ahead.
     */
    private function paidUntil(ShopifyInstall $install): ?CarbonInterface
    {
        $subscriptions = collect((array) ($this->api->query($install, self::ACTIVE)['currentAppInstallation']['activeSubscriptions'] ?? []));
        $active = $subscriptions->first(fn (array $s): bool => (string) ($s['id'] ?? '') === $install->subscription_id)
            ?? $subscriptions->first(fn (array $s): bool => strtoupper((string) ($s['status'] ?? '')) === 'ACTIVE');
        $trialDays = (int) ($active['trialDays'] ?? 0);

        $ends = array_filter([
            $install->trial_ends_at,
            $trialDays > 0 ? self::moment($active['createdAt'] ?? null)?->addDays($trialDays) : null,
        ], fn (?CarbonInterface $at): bool => $at !== null && $at->isFuture());

        if ($ends !== []) {
            return max($ends);
        }

        $period = self::moment($active['currentPeriodEnd'] ?? null);

        return $period !== null && $period->isFuture() ? $period : null;
    }

    private static function moment(mixed $value): ?CarbonInterface
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Where the merchant approves the plan in Shopify. */
    public function start(ShopifyInstall $install, string $returnUrl, ?Plan $plan = null): string
    {
        $dev = (bool) ($this->api->query($install, 'query { shop { plan { partnerDevelopment } } }')['shop']['plan']['partnerDevelopment'] ?? false);

        $answer = (array) ($this->api->query($install, self::CREATE, [
            'name' => $plan?->billingName() ?? (string) Settings::get('shopify.plan_name'),
            'returnUrl' => $returnUrl,
            'trialDays' => self::trialDaysLeft($install),
            'test' => $dev || (bool) Settings::get('shopify.billing_test'),
            'lineItems' => [[
                'plan' => ['appRecurringPricingDetails' => [
                    'price' => ['amount' => $plan?->price() ?? (float) Settings::get('shopify.plan_price_usd'), 'currencyCode' => 'USD'],
                    'interval' => 'EVERY_30_DAYS',
                ]],
            ]],
        ])['appSubscriptionCreate'] ?? []);

        $url = $answer['confirmationUrl'] ?? null;

        if (! is_string($url) || ($answer['userErrors'] ?? []) !== []) {
            throw new RuntimeException('Shopify refused the subscription: '.json_encode($answer['userErrors'] ?? $answer));
        }

        $install->forceFill([
            'subscription_id' => (string) ($answer['appSubscription']['id'] ?? ''),
            'subscription_status' => 'pending',
        ])->save();

        return $url;
    }

    /**
     * The trial days a new subscription for this store gets: shopify.trial_days for a store that never had a plan;
     * the whole days left until trial_ends_at for one in its trial (a plan changed, the app installed again: the
     * install is kept by the store's address); none once the trial ended, or after a plan without one. Whole days,
     * rounded down, so changing plans never lengthens the trial.
     */
    public static function trialDaysLeft(ShopifyInstall $install): int
    {
        $full = max(0, (int) Settings::get('shopify.trial_days'));

        if ($install->trial_ends_at !== null) {
            return min($full, max(0, (int) floor(now()->diffInSeconds($install->trial_ends_at, false) / 86400)));
        }

        return $install->subscribed_at === null ? $full : 0;
    }

    /**
     * Whether this store is asked to pay: charging is on, and the store came through the public app. A
     * custom-distribution app made for one store (SHOPIFY_MORE_APPS, before the public one was approved)
     * stays as agreed with that store, free: turning charging on for the App Store never pauses its search.
     */
    public static function charges(ShopifyInstall $install): bool
    {
        return (bool) Settings::get('shopify.charge')
            && ShopifyApps::key($install->client_id) === ShopifyApps::key();
    }

    /** Reads the store's subscription from Shopify and sets the install and the shop by it. */
    public function refresh(ShopifyInstall $install): string
    {
        $active = collect((array) ($this->api->query($install, self::ACTIVE)['currentAppInstallation']['activeSubscriptions'] ?? []))
            ->first(fn (array $s): bool => strtoupper((string) ($s['status'] ?? '')) === 'ACTIVE');

        if ($active !== null) {
            $trialDays = (int) ($active['trialDays'] ?? 0);
            $install->forceFill([
                'subscription_id' => (string) $active['id'],
                'subscription_status' => ShopifyInstall::ACTIVE,
                'subscribed_at' => $install->subscribed_at ?? now(),
                'trial_ends_at' => $trialDays > 0 ? now()->parse((string) $active['createdAt'])->addDays($trialDays) : null,
            ])->save();
            $this->planFrom($install, (string) ($active['name'] ?? ''));
        } elseif (! self::charges($install)) {
            $install->forceFill(['subscription_status' => ShopifyInstall::FREE])->save();
        } elseif ($install->subscribed()) {
            $install->forceFill(['subscription_status' => 'cancelled'])->save();
        }

        $this->follow($install);

        return $install->subscription_status;
    }

    /** What Shopify reported in a webhook: the status of one subscription. */
    public function reported(ShopifyInstall $install, string $subscriptionId, string $status): void
    {
        $status = strtolower($status);

        if ($install->subscription_id !== null && $install->subscription_id !== $subscriptionId && $status !== 'active') {
            return; // an older subscription ended; the current one stands
        }

        $install->forceFill(['subscription_id' => $subscriptionId, 'subscription_status' => $status] + ($status === 'active' ? ['subscribed_at' => $install->subscribed_at ?? now()] : []))->save();
        $this->follow($install);
    }

    /** The shop is on the plan its live subscription names; a name no plan has leaves the shop as it is. */
    private function planFrom(ShopifyInstall $install, string $name): void
    {
        $plan = Plan::query()->get()->first(fn (Plan $p): bool => $p->billingName() === $name);
        $shop = $plan === null ? null : Shop::query()->find($install->shop_id);

        if ($shop !== null && $shop->plan !== $plan->code) {
            $shop->forceFill(['plan' => $plan->code])->save();
        }
    }

    /**
     * The shop is active exactly while the store has the app and a live subscription, or a cancelled plan still runs
     * to the end of its paid period (Shop::planRunsUntil): until then a cancellation Shopify reports changes nothing.
     * Removing the app ends that period at once. A plan approved again after the owner ended one is a plan again;
     * a shop waiting to be deleted stays paused whatever Shopify says.
     */
    public function follow(ShopifyInstall $install): void
    {
        $shop = Shop::query()->find($install->shop_id);

        if ($shop === null || $shop->status === ShopStatus::Disabled || $shop->leaving()) {
            return;
        }

        if ($install->subscribed()) {
            $shop->forceFill(['status' => ShopStatus::Active, 'cancelled_at' => null, 'plan_ends_at' => null])->save();

            return;
        }

        if ($install->installed() && $shop->planRunsUntil() !== null) {
            return;
        }

        $shop->forceFill(['status' => ShopStatus::Paused, 'plan_ends_at' => null])->save();
    }
}
