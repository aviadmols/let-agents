<?php

namespace App\Modules\Shopify\Actions;

use App\Core\Facades\Settings;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApi;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Shop;
use RuntimeException;

/**
 * The app's monthly plan, billed by Shopify on the merchant's Shopify invoice every 30 days, after
 * shopify.trial_days: the plan the merchant chose (Plan: its name on the bill and its price), or
 * shopify.plan_name at shopify.plan_price_usd when none is offered. A development store is billed
 * in test mode, so a test install never charges anyone. The active subscription's name says the
 * shop's plan again: an upgrade holds from its approval, a downgrade from the next period (Shopify).
 *
 * The shop is active while the subscription is, and paused otherwise: the storefront goes quiet
 * and nothing is lost. While shopify.charge is off nobody is billed: a store without a subscription
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
        mutation ($id: ID!) {
          appSubscriptionCancel(id: $id) {
            appSubscription { id status }
            userErrors { field message }
          }
        }
        GQL;

    public function __construct(private readonly ShopifyApi $api) {}

    /** The owner ended the plan: Shopify stops billing at once, and the shop pauses. */
    public function cancel(ShopifyInstall $install): void
    {
        if ($install->subscription_id !== null && $install->subscription_id !== '') {
            $answer = (array) ($this->api->query($install, self::CANCEL, ['id' => $install->subscription_id])['appSubscriptionCancel'] ?? []);

            if (($answer['userErrors'] ?? []) !== []) {
                throw new RuntimeException('Shopify refused to cancel the subscription: '.json_encode($answer['userErrors']));
            }
        }

        $install->forceFill(['subscription_status' => 'cancelled'])->save();
        $this->follow($install);
    }

    /** Where the merchant approves the plan in Shopify. */
    public function start(ShopifyInstall $install, string $returnUrl, ?Plan $plan = null): string
    {
        $dev = (bool) ($this->api->query($install, 'query { shop { plan { partnerDevelopment } } }')['shop']['plan']['partnerDevelopment'] ?? false);

        $answer = (array) ($this->api->query($install, self::CREATE, [
            'name' => $plan?->billingName() ?? (string) Settings::get('shopify.plan_name'),
            'returnUrl' => $returnUrl,
            'trialDays' => (int) Settings::get('shopify.trial_days'),
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
        } elseif (! (bool) Settings::get('shopify.charge')) {
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
     * The shop is active exactly while the store has the app and a live subscription. A plan approved again after
     * the owner ended one is a plan again; a shop waiting to be deleted stays paused whatever Shopify says.
     */
    public function follow(ShopifyInstall $install): void
    {
        $shop = Shop::query()->find($install->shop_id);

        if ($shop === null || $shop->status === ShopStatus::Disabled || $shop->leaving()) {
            return;
        }

        $subscribed = $install->subscribed();
        $shop->forceFill(['status' => $subscribed ? ShopStatus::Active : ShopStatus::Paused] + ($subscribed ? ['cancelled_at' => null] : []))->save();
    }
}
