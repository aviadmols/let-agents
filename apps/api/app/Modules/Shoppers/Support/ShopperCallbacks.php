<?php

namespace App\Modules\Shoppers\Support;

use App\Modules\Admin\Contracts\ReplyLink;
use App\Modules\Admin\Contracts\ShopperRequests;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Shoppers\Models\ShopperVisitor;
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
        $rows = ShopperCallback::query()->with('identity:id,channel')->whereNull('answered_at')
            ->latest('created_at')->orderByDesc('id')->limit($limit)->get();
        // the browser each identity was last linked from (the later link wins), for "see their visit"
        $visitors = $rows->isEmpty() ? collect() : ShopperVisitor::query()->whereIn('identity_id', $rows->pluck('identity_id')->unique()->values())
            ->orderBy('linked_at')->orderBy('id')->pluck('visitor_hash', 'identity_id');
        $pages = self::pages($rows);

        return $rows->map(fn (ShopperCallback $row): array => [
            'key' => self::PREFIX.$row->id,
            'name' => is_string($row->name) && trim($row->name) !== '' ? trim($row->name) : null,
            'channel' => $row->identity?->channel === Contact::EMAIL ? 'email' : 'phone',
            'question' => trim((string) $row->question),
            'at' => $row->created_at,
            'visitor' => $visitors->get($row->identity_id),
            // the page they asked on: what the question is about (none from the search box)
            'page' => $pages[$row->page_type.'|'.$row->page_id] ?? null,
        ])->all();
    }

    /**
     * The pages these were asked on, by their type and id: a product with its title, link and picture, an article with
     * its title and link; none for the search box, or a page no longer in the catalogue.
     *
     * @param  iterable<object>  $rows
     * @return array<string, array{kind: string, title: string, url: string|null, image: string|null}> "type|id" => page
     */
    private static function pages(iterable $rows): array
    {
        $ids = ['product' => [], 'content' => []];
        foreach ($rows as $row) {
            $type = (string) $row->page_type;
            if (isset($ids[$type]) && (string) $row->page_id !== '') {
                $ids[$type][] = (string) $row->page_id;
            }
        }

        $pages = [];
        if ($ids['product'] !== []) {
            foreach (CatalogProduct::query()->whereIn('external_id', array_unique($ids['product']))->get(['external_id', 'title', 'url', 'image_url']) as $p) {
                $pages['product|'.$p->external_id] = ['kind' => 'product', 'title' => (string) $p->title, 'url' => $p->url, 'image' => $p->image_url];
            }
        }
        if ($ids['content'] !== []) {
            foreach (CatalogContent::query()->whereIn('external_id', array_unique($ids['content']))->get(['external_id', 'title', 'url']) as $c) {
                $pages['content|'.$c->external_id] = ['kind' => 'content', 'title' => (string) $c->title, 'url' => $c->url, 'image' => null];
            }
        }

        return $pages;
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
