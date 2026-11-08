<?php

namespace App\Modules\Shoppers\Support;

use App\Modules\Admin\Contracts\ReplyLink;
use App\Modules\Admin\Contracts\ShopperRequests;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The shoppers who left a phone or an email when the assistant did not know (shopper_callbacks), on the Shoppers
 * screen's "Waiting for you", and the people who signed up for updates. Runs inside the shop's TenantContext.
 */
final class ShopperCallbacks implements ShopperRequests
{
    private const PREFIX = 'callback:';

    public function waiting(Shop $shop, int $limit): array
    {
        return ShopperCallback::query()->with('identity:id,channel')->whereNull('answered_at')
            ->latest('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (ShopperCallback $row): array => [
                'key' => self::PREFIX.$row->id,
                'name' => is_string($row->name) && trim($row->name) !== '' ? trim($row->name) : null,
                'channel' => $row->identity?->channel === Contact::EMAIL ? 'email' : 'phone',
                'question' => trim((string) $row->question),
                'at' => $row->created_at,
            ])->all();
    }

    public function replyUrl(Shop $shop, string $key, ?int $userId): ?string
    {
        $row = $this->callback($key);
        $identity = $row?->identity;

        return $identity === null ? null : ReplyLink::make($identity->channel === Contact::EMAIL ? 'email' : 'phone', (string) $identity->contact, $row->name, (string) $row->question);
    }

    public function answered(Shop $shop, string $key, ?int $userId): bool
    {
        $row = $this->callback($key);

        return $row !== null && $row->forceFill(['answered_at' => now()])->save();
    }

    public function signUps(Shop $shop, CarbonInterface $since, CarbonInterface $until): int
    {
        return ShopperIdentity::query()->where('created_at', '>=', $since)->where('created_at', '<', $until)->count();
    }

    private function callback(string $key): ?ShopperCallback
    {
        return str_starts_with($key, self::PREFIX)
            ? ShopperCallback::query()->with('identity')->whereKey(substr($key, strlen(self::PREFIX)))->whereNull('answered_at')->first()
            : null;
    }
}
