<?php

namespace App\Modules\Shortlist\Actions;

use App\Core\Facades\Settings;
use App\Core\Http\Visit;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use Illuminate\Support\Str;

/**
 * A shopper's wishlist, kept on our side so it is there on their next visit, on another device once
 * they sign in, and behind a share link. A guest's list is their browser's (a random token, kept
 * only as its hash). Signed in to the store, the list is the account's: a guest list from this
 * browser joins it, newest first, and the browser's own copy follows the account's from then on.
 *
 * The browser sends what changed (added, the first on top; removed), never its whole list, so a
 * stale copy on one device does not undo a removal made on another. Run inside the shop's tenant
 * context.
 */
final class KeepWishlist
{
    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @param  array<string, string|null>  $visit  the visit that changes it (App\Core\Http\Visit), kept on the list
     * @return array{items: list<string>, share: string|null, account: bool}
     */
    public function handle(string $shopId, ?string $token, ?string $customer, array $add = [], array $remove = [], array $visit = []): array
    {
        $max = (int) Settings::get('shortlist.wishlist_max', $shopId);
        $hash = $token !== null && preg_match('/^[A-Za-z0-9_-]{24,64}$/', $token) === 1 ? hash('sha256', $token) : null;
        $guest = $hash === null ? null : ShortlistWishlist::query()->where('token_hash', $hash)->whereNull('customer_ref')->first();

        if ($customer !== null) {
            $list = ShortlistWishlist::query()->where('customer_ref', $customer)->first();

            if ($list === null && $guest !== null) {
                // The guest becomes the account: the same list, now theirs wherever they sign in.
                $guest->forceFill(['customer_ref' => $customer, 'token_hash' => null]);
                $list = $guest;
            } elseif ($guest !== null) {
                $list->items = self::merge((array) $guest->items, (array) $list->items, $max);
                $guest->delete();
            }
        } else {
            $list = $guest;
        }

        if ($list === null) {
            if ($add === [] || ($hash === null && $customer === null)) {
                return ['items' => [], 'share' => null, 'account' => $customer !== null];
            }
            $list = new ShortlistWishlist(['shop_id' => $shopId, 'token_hash' => $customer === null ? $hash : null, 'customer_ref' => $customer, 'share_code' => self::code(), 'items' => []]);
        }

        $items = array_values(array_diff((array) $list->items, $remove));
        $list->items = self::merge($add, $items, $max);

        // The last visit that changed it, for the store manager; a mere look changes nothing.
        if ($visit !== [] && ($add !== [] || $remove !== [] || ! $list->exists)) {
            $list->fill(array_intersect_key($visit, array_flip(Visit::COLUMNS)));
        }

        if (! $list->exists || $list->isDirty()) {
            $list->save();
        }

        return ['items' => $list->items, 'share' => $list->share_code, 'account' => $customer !== null];
    }

    /** The products of a shared list, by its code; null when there is none. */
    public function shared(string $code): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{8,16}$/', $code) !== 1) {
            return null;
        }

        return ShortlistWishlist::query()->where('share_code', $code)->first()?->items;
    }

    /**
     * The newer first, then the older, each product once, at most $max.
     *
     * @param  list<string>  $newer
     * @param  list<string>  $older
     * @return list<string>
     */
    private static function merge(array $newer, array $older, int $max): array
    {
        return array_slice(array_values(array_unique([...$newer, ...$older])), 0, $max);
    }

    private static function code(): string
    {
        do {
            $code = Str::random(12);
        } while (ShortlistWishlist::query()->withoutGlobalScopes()->where('share_code', $code)->exists());

        return $code;
    }
}
