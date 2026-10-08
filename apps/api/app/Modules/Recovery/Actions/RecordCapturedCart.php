<?php

namespace App\Modules\Recovery\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Recovery\Models\RecoveryCart;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A cart a shopper left their email for, as the store's plugin reports it after it kept the cart
 * as an order waiting for payment. The same order reported again (the shopper changed the cart
 * and pressed again) updates the same row.
 *
 * Only with consent, and only what explains the cart: the email (encrypted), the products, the
 * total, the browser's visitor id as a salted hash, and the searches that browser made.
 */
final class RecordCapturedCart
{
    private const MAX_ITEMS = 100;

    private const MAX_SEARCHES = 20;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{stored: bool, problems: list<string>}
     */
    public function handle(StoreConnection $connection, array $payload): array
    {
        $problems = [];
        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $ref = (string) ($payload['order_ref'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'email';
        }

        if (($payload['consent'] ?? null) !== true) {
            $problems[] = 'consent';
        }

        if (! preg_match('/^[a-f0-9]{16,64}$/', $ref)) {
            $problems[] = 'order_ref';
        }

        $items = $this->items($payload['items'] ?? null);

        if ($items === []) {
            $problems[] = 'items';
        }

        if ($problems !== []) {
            return ['stored' => false, 'problems' => $problems];
        }

        $shopId = (string) $connection->shop_id;

        // A shop that turned the popup off keeps nothing new, whatever a cached script sent.
        if (! Features::enabled('recovery.capture', $shopId)) {
            return ['stored' => false, 'problems' => []];
        }

        $this->tenant->run($shopId, function () use ($shopId, $connection, $payload, $email, $ref, $items): void {
            $cart = RecoveryCart::query()->firstOrNew(['shop_id' => $shopId, 'order_ref' => $ref]);
            $vid = (string) ($payload['vid'] ?? '');

            $cart->fill([
                'platform' => (string) ($connection->platform ?: 'woocommerce'),
                'store_order_id' => isset($payload['order_id']) ? mb_substr((string) $payload['order_id'], 0, 40) : null,
                'admin_url' => $this->url($payload['admin_url'] ?? null),
                'email' => $email,
                'email_hash' => hash('sha256', $shopId.'|email|'.$email),
                'email_masked' => self::mask($email),
                'consent_text' => mb_substr(trim((string) ($payload['consent_text'] ?? '')), 0, 1000) ?: null,
                'consented_at' => now(),
                // The same salted hash analytics keeps, so the visit that led here can be read.
                'visitor_hash' => $vid !== '' ? hash('sha256', $shopId.'|'.$vid) : null,
                'items' => $items,
                'total' => is_numeric($payload['total'] ?? null) ? (string) $payload['total'] : '0',
                'currency' => is_string($payload['currency'] ?? null) ? mb_substr(strtoupper($payload['currency']), 0, 3) : null,
                'searches' => $this->searches($payload['searches'] ?? null),
                'captured_at' => $this->date($payload['captured_at'] ?? null),
            ]);

            // A cart changed and captured again is waiting again, whatever was reported before.
            if ($cart->isDirty('items')) {
                $cart->status = RecoveryCart::OPEN;
                $cart->report = null;
                $cart->reported_at = null;
            }

            $cart->status ??= RecoveryCart::OPEN;
            $cart->save();
        });

        return ['stored' => true, 'problems' => []];
    }

    /** "dana.levi@gmail.com" is "d***i@gmail.com": enough to recognise, not to read. */
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $shown = mb_strlen($name) <= 2 ? mb_substr($name, 0, 1).'***' : mb_substr($name, 0, 1).'***'.mb_substr($name, -1);

        return mb_substr($shown.'@'.$domain, 0, 120);
    }

    /** @return list<array{product_id: string, variation_id: string|null, quantity: int, total: string}> */
    private function items(mixed $items): array
    {
        $out = [];

        foreach (array_slice(is_array($items) ? $items : [], 0, self::MAX_ITEMS) as $item) {
            $id = is_array($item) ? (string) ($item['product_id'] ?? '') : '';

            if (! preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id)) {
                continue;
            }

            $variation = (string) ($item['variation_id'] ?? '');
            $out[] = [
                'product_id' => $id,
                'variation_id' => preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $variation) ? $variation : null,
                'quantity' => max(1, min(9999, (int) ($item['quantity'] ?? 1))),
                'total' => is_numeric($item['total'] ?? null) ? (string) $item['total'] : '0',
            ];
        }

        return $out;
    }

    /** @return list<array{q: string, at: string|null}> */
    private function searches(mixed $searches): array
    {
        $out = [];

        foreach (array_slice(is_array($searches) ? $searches : [], 0, self::MAX_SEARCHES) as $search) {
            $q = trim(mb_substr(is_array($search) ? (string) ($search['q'] ?? '') : '', 0, 120));

            if ($q !== '') {
                $out[] = ['q' => $q, 'at' => is_array($search) && is_string($search['at'] ?? null) ? mb_substr($search['at'], 0, 40) : null];
            }
        }

        return $out;
    }

    private function url(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 2000) : null;
    }

    private function date(mixed $value): Carbon
    {
        try {
            $date = is_string($value) ? Carbon::parse($value) : now();
        } catch (Throwable) {
            $date = now();
        }

        // A clock far off on the store's side never moves a cart into the future or far back.
        return $date->between(now()->subDay(), now()->addMinutes(5)) ? $date : now();
    }
}
