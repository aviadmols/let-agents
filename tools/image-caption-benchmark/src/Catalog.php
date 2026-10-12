<?php

namespace ImageCaptionBenchmark;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A Shopify store's public catalogue: products.json, collections.json, and the pictures. Nothing
 * from the production database: metafields and inventory are not public, so the meta objects say
 * they are missing instead of guessing them.
 */
final class Catalog
{
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const MAX_COLLECTIONS = 60;

    public function __construct(private readonly string $shop) {}

    /**
     * Up to $limit products with a picture, spread evenly over the store's first pages, so one kind
     * of product does not fill the sample.
     *
     * @return list<array<string, mixed>>
     */
    public function products(int $limit, int $pages = 2): array
    {
        $all = [];

        for ($page = 1; $page <= $pages; $page++) {
            $batch = (array) $this->json('/products.json?limit=250&page='.$page, 'products');
            $all = [...$all, ...array_filter($batch, fn (array $p): bool => ($p['images'][0]['src'] ?? '') !== '')];

            if (count($batch) < 250) {
                break;
            }
        }

        if (count($all) <= $limit) {
            return array_values($all);
        }

        $step = count($all) / $limit;

        return array_values(array_map(fn (int $i): array => $all[(int) floor($i * $step)], range(0, $limit - 1)));
    }

    /**
     * The collections each product is in, by product id. Read only for the products asked about.
     *
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    public function collections(array $ids): array
    {
        $wanted = array_flip($ids);
        $found = [];

        foreach (array_slice((array) $this->json('/collections.json?limit=250', 'collections'), 0, self::MAX_COLLECTIONS) as $collection) {
            $products = (array) $this->json('/collections/'.rawurlencode((string) $collection['handle']).'/products.json?limit=250', 'products');

            foreach ($products as $product) {
                $id = (string) $product['id'];

                if (isset($wanted[$id])) {
                    $found[$id][] = trim((string) $collection['title']);
                }
            }
        }

        return $found;
    }

    /** The picture as production's CaptionImages fetches it, or null when it would skip it. @return array{mime: string, data: string}|null */
    public function picture(string $url, int $maxKb): ?array
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)->withHeaders(['Accept' => 'image/*'])->get($url);
        } catch (ConnectionException) {
            return null;
        }

        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        return $response->successful() && in_array($mime, self::MIMES, true) && $body !== '' && strlen($body) <= $maxKb * 1024
            ? ['mime' => $mime, 'data' => $body]
            : null;
    }

    private function json(string $path, string $key): mixed
    {
        try {
            $response = Http::timeout(30)->connectTimeout(10)->acceptJson()->get(rtrim($this->shop, '/').$path);
        } catch (ConnectionException) {
            return [];
        }

        return $response->successful() ? $response->json($key, []) : [];
    }
}
