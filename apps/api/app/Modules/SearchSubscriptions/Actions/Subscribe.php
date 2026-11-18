<?php

namespace App\Modules\SearchSubscriptions\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\SearchSubscriptions\Mail\ConfirmSubscriptionMail;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A shopper subscribes to a search from the drawer: kept as pending, with the words they agreed to, the time and their
 * IP's hash, and the confirmation email sent (double opt-in). Nothing else is sent until they confirm.
 *
 * The same address and search again: an active one is said so; a pending one gets its email again (not more than once a
 * minute); a stopped one starts again as pending, with the new consent.
 */
final class Subscribe
{
    public const SENT = 'confirm_sent';

    public const ALREADY = 'already';

    public const TOO_MANY = 'too_many';

    public const INVALID_EMAIL = 'invalid_email';

    public const NO_CONSENT = 'no_consent';

    public const NOT_KEPT = 'not_keepable';

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array{status: string, subscription?: SearchSubscription} */
    public function handle(string $shopId, string $email, string $query, string $locale, bool $consent, string $consentText, ?string $ipHash): array
    {
        if (! $consent) {
            return ['status' => self::NO_CONSENT];
        }
        if (! Subscribers::validEmail($email)) {
            return ['status' => self::INVALID_EMAIL];
        }
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        if (! Subscribers::keepable($query)) {
            return ['status' => self::NOT_KEPT];
        }

        return $this->tenant->run($shopId, function () use ($shopId, $email, $query, $locale, $consentText, $ipHash): array {
            $hash = Subscribers::hash($email);
            $key = Subscribers::queryKey($query);
            $subscription = SearchSubscription::query()->where('email_hash', $hash)->where('query_key', $key)->first();

            if ($subscription?->status === SearchSubscription::ACTIVE) {
                return ['status' => self::ALREADY, 'subscription' => $subscription];
            }
            $open = SearchSubscription::query()->where('email_hash', $hash)->whereIn('status', [SearchSubscription::ACTIVE, SearchSubscription::PENDING])->count();
            if ($subscription === null && $open >= (int) Settings::get('search_subscriptions.searches_per_email')) {
                return ['status' => self::TOO_MANY];
            }

            $fields = [
                'email' => Subscribers::normalEmail($email), 'query' => $query, 'locale' => $locale === 'en' ? 'en' : 'he',
                'consent_text' => mb_substr($consentText, 0, 1000), 'consent_at' => now(), 'consent_ip_hash' => $ipHash,
            ];
            if ($subscription === null) {
                $subscription = SearchSubscription::query()->create($fields + ['shop_id' => $shopId, 'email_hash' => $hash, 'query_key' => $key, 'status' => SearchSubscription::PENDING]);
            } elseif ($subscription->status === SearchSubscription::STOPPED) {
                $subscription->forceFill($fields + ['status' => SearchSubscription::PENDING, 'stop_reason' => null, 'stopped_at' => null, 'confirmed_at' => null, 'expires_at' => null, 'reminded_at' => null])->save();
            } elseif ($subscription->confirm_sent_at !== null && $subscription->confirm_sent_at->gt(now()->subMinute())) {
                return ['status' => self::SENT, 'subscription' => $subscription];
            }

            $this->sendConfirmation($subscription);

            return ['status' => self::SENT, 'subscription' => $subscription];
        });
    }

    /** The confirmation email, in the store's name. Call inside the shop's tenant. */
    public function sendConfirmation(SearchSubscription $subscription): void
    {
        $shop = Shop::query()->find($subscription->shop_id);
        try {
            Mail::to($subscription->email)->send(new ConfirmSubscriptionMail($subscription, (string) ($shop?->name ?? '')));
            $subscription->forceFill(['confirm_sent_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('search subscriptions: the confirmation email failed', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
        }
    }
}
