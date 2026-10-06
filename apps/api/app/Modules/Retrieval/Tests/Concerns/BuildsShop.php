<?php

namespace App\Modules\Retrieval\Tests\Concerns;

use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;

/**
 * A small shop straight into the tables, and an embedder that needs no network: a bag of words
 * folded into a short vector, so texts that share words really are near each other.
 */
trait BuildsShop
{
    protected Shop $shop;

    protected object $embedder;

    protected function buildShop(): void
    {
        $this->shop = Shop::factory()->create();

        $this->embedder = new class implements Embedder
        {
            public int $calls = 0;

            /** @var list<string> */
            public array $texts = [];

            public ?string $model = null;

            public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings
            {
                $this->calls++;
                $this->model = $model;
                array_push($this->texts, ...$texts);

                return new Embeddings(array_map(fn (string $text): array => self::vector($text), $texts), count($texts) * 10);
            }

            /** @return list<float> */
            public static function vector(string $text): array
            {
                $vector = array_fill(0, 48, 0.0);

                foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $word) {
                    if (mb_strlen($word) > 1) {
                        $vector[crc32($word) % 48] += 1.0;
                    }
                }

                return $vector;
            }
        };

        $this->app->instance(Embedder::class, $this->embedder);
    }

    protected function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    /** @param array<string, mixed> $overrides */
    protected function product(string $externalId, string $title, string $description, array $overrides = []): CatalogProduct
    {
        return $this->inShop(fn () => CatalogProduct::query()->create(array_replace([
            'shop_id' => $this->shop->id,
            'external_id' => $externalId,
            'type' => 'simple',
            'status' => 'publish',
            'title' => $title,
            'price' => '499.00',
            'currency' => 'ILS',
            'in_stock' => true,
            'purchasable' => true,
            'hash' => md5($title.$description),
            'payload' => [
                'title' => $title,
                'short_description' => '',
                'description' => $description,
                'categories' => [['id' => '1', 'name' => 'כלי עבודה', 'path' => ['כלי עבודה']]],
                'attributes' => [],
                'meta' => [],
            ],
        ], $overrides)));
    }

    /** @param list<string> $products external IDs the article names */
    protected function article(string $externalId, string $title, string $body, array $products = []): CatalogContent
    {
        return $this->inShop(fn () => CatalogContent::query()->create([
            'shop_id' => $this->shop->id,
            'type' => 'post',
            'external_id' => $externalId,
            'title' => $title,
            'excerpt' => mb_substr($body, 0, 80),
            'body' => $body,
            'product_external_ids' => $products === [] ? null : $products,
            'hash' => md5($title.$body),
        ]));
    }

    /** @param list<string> $products external IDs in one order */
    protected function order(array $products, string $source = 'live', string $when = '-10 days'): void
    {
        static $n = 0;
        $n++;

        $this->inShop(fn () => AnalyticsOrder::query()->create([
            'shop_id' => $this->shop->id,
            'order_ref' => hash('sha256', 'order-'.$n.'-'.implode(',', $products)),
            'source' => $source,
            'total' => 100,
            'currency' => 'ILS',
            'items_count' => count($products),
            'items' => array_map(fn (string $id): array => ['product_id' => $id, 'quantity' => 1, 'total' => 50], $products),
            'ordered_at' => now()->modify($when),
        ]));
    }
}
