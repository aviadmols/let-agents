<?php

namespace App\Modules\Shortlist\Actions;

use App\Core\Facades\Settings;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Sms\Contracts\Phone;
use App\Modules\Sms\Contracts\SendsSms;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A wishlist kept by the shopper's phone. The shopper types a number, gets a four-digit code by
 * SMS from the platform's sender, types it back, and the list is the number's from then on, on
 * any device; the browser's own list joins it, newest first. The browser keeps a sign-in token
 * (only its hash is kept here) and sends it with every change.
 *
 * Codes live ten minutes and allow five tries; a number gets a few codes an hour, an address a few
 * more, a shop a bounded number a day (shortlist.phone_codes_*). Inside the shop's tenant.
 */
final class PhoneSignIn
{
    private const CODE_MINUTES = 10;

    private const TRIES = 5;

    public function __construct(
        private readonly SendsSms $sms,
        private readonly KeepWishlist $keep,
    ) {}

    /** @return array{sent: bool, error?: string} */
    public function sendCode(string $shopId, string $typed, string $ip): array
    {
        $phone = Phone::normalize($typed);

        if ($phone === null) {
            return ['sent' => false, 'error' => 'bad_phone'];
        }

        if (! $this->sms->ready()) {
            return ['sent' => false, 'error' => 'unavailable'];
        }

        $key = Phone::key($phone);
        $ipKey = hash('sha256', $ip);
        $recent = fn (string $column, string $value, int $minutes): int => DB::table('shortlist_phone_codes')
            ->where('shop_id', $shopId)->where($column, $value)->where('created_at', '>=', now()->subMinutes($minutes))->count();

        if ($recent('phone_key', $key, 60) >= (int) Settings::get('shortlist.phone_codes_per_hour', $shopId)
            || $recent('ip_key', $ipKey, 60) >= (int) Settings::get('shortlist.phone_codes_per_ip_hour', $shopId)
            || DB::table('shortlist_phone_codes')->where('shop_id', $shopId)->where('created_at', '>=', now()->startOfDay())->count() >= (int) Settings::get('shortlist.phone_codes_per_day', $shopId)) {
            return ['sent' => false, 'error' => 'too_many'];
        }

        $code = (string) random_int(1000, 9999);
        $shop = (string) (Shop::query()->whereKey($shopId)->value('name') ?? '');
        $sent = $this->sms->send($phone, (string) __('shortlist::storefront.phone_sms', ['code' => $code, 'shop' => $shop], 'he'), 'wishlist_code', $shopId);

        if (! $sent->ok) {
            return ['sent' => false, 'error' => 'unavailable'];
        }

        DB::table('shortlist_phone_codes')->insert([
            'shop_id' => $shopId, 'phone_key' => $key, 'code_hash' => self::codeHash($key, $code), 'ip_key' => $ipKey,
            'attempts' => 0, 'expires_at' => now()->addMinutes(self::CODE_MINUTES), 'created_at' => now(),
        ]);

        return ['sent' => true];
    }

    /**
     * The code checked; on success the list is the number's, the browser's list joined to it.
     *
     * @return array{ok: bool, error?: string, session?: string, phone?: string}
     */
    public function verify(string $shopId, string $typed, string $code, ?string $browserToken, bool $consent): array
    {
        $phone = Phone::normalize($typed);

        if ($phone === null || preg_match('/^\d{4}$/', trim($code)) !== 1) {
            return ['ok' => false, 'error' => 'bad_code'];
        }

        $key = Phone::key($phone);
        $row = DB::table('shortlist_phone_codes')->where('shop_id', $shopId)->where('phone_key', $key)
            ->whereNull('used_at')->where('expires_at', '>', now())->orderByDesc('id')->first();

        if ($row === null || $row->attempts >= self::TRIES) {
            return ['ok' => false, 'error' => 'expired'];
        }

        DB::table('shortlist_phone_codes')->where('id', $row->id)->increment('attempts');

        if (! hash_equals((string) $row->code_hash, self::codeHash($key, trim($code)))) {
            return ['ok' => false, 'error' => 'bad_code'];
        }

        DB::table('shortlist_phone_codes')->where('id', $row->id)->update(['used_at' => now()]);

        // The browser's list joins the number's (KeepWishlist merges a guest list into an account's).
        $this->keep->handle($shopId, $browserToken, self::ref($key));
        $list = ShortlistWishlist::query()->where('customer_ref', self::ref($key))->first()
            ?? new ShortlistWishlist(['shop_id' => $shopId, 'customer_ref' => self::ref($key), 'share_code' => Str::random(12), 'items' => []]);
        $list->forceFill([
            'phone' => $phone,
            'phone_verified_at' => now(),
            // Given once, kept; never assumed.
            'marketing_consent' => $list->marketing_consent || $consent,
        ])->save();

        $session = Str::random(40);
        DB::table('shortlist_phone_sessions')->insert(['shop_id' => $shopId, 'phone_key' => $key, 'token_hash' => hash('sha256', $session), 'last_used_at' => now(), 'created_at' => now()]);

        return ['ok' => true, 'session' => $session, 'phone' => Phone::masked($phone)];
    }

    /** The list a signed-in browser's token names ("phone:<key>"), or null. */
    public function resolve(string $shopId, string $session): ?string
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $session) !== 1) {
            return null;
        }

        $row = DB::table('shortlist_phone_sessions')->where('shop_id', $shopId)->where('token_hash', hash('sha256', $session))->first();

        if ($row === null) {
            return null;
        }

        if ($row->last_used_at === null || now()->diffInHours($row->last_used_at, true) >= 24) {
            DB::table('shortlist_phone_sessions')->where('id', $row->id)->update(['last_used_at' => now()]);
        }

        return self::ref((string) $row->phone_key);
    }

    /** Signed out on this browser: its token no longer names the list. */
    public function signOut(string $shopId, string $session): void
    {
        DB::table('shortlist_phone_sessions')->where('shop_id', $shopId)->where('token_hash', hash('sha256', $session))->delete();
    }

    /** "050-•••-4567" for the list a reference names, when it is a phone's. */
    public static function maskedFor(string $ref): ?string
    {
        if (! str_starts_with($ref, 'phone:')) {
            return null;
        }

        $phone = ShortlistWishlist::query()->where('customer_ref', $ref)->first()?->phone;

        return is_string($phone) ? Phone::masked($phone) : null;
    }

    public static function ref(string $key): string
    {
        return 'phone:'.$key;
    }

    private static function codeHash(string $key, string $code): string
    {
        return hash_hmac('sha256', $key.'|'.$code, (string) config('app.key'));
    }
}
