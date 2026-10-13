<?php

namespace App\Modules\Improvement\Actions;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Contracts\StoreBlog;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Improvement\Support\PostDocument;
use App\Modules\Tenancy\Models\Shop;

/**
 * A published post goes to the store's own blog, as a draft the store owner publishes from their
 * own site: the page as code built it (PostDocument), with its picture, SEO fields and questions.
 * Where it went is kept on the post, so sending it again updates that draft; why it could not go
 * is kept too, for the screen to say and to try again. No model is called here. Callers are
 * inside the shop's tenant.
 */
final class SendPostToStore
{
    public function __construct(private readonly StoreBlog $blog) {}

    public function handle(ImprovementPost $post): ImprovementPost
    {
        $connection = StoreConnection::query()->where('shop_id', $post->shop_id)->first();
        $remote = (array) ($post->remote ?? []);

        if ($connection === null) {
            return $this->keep($post, array_replace($remote, ['status' => 'failed', 'error' => 'not_installed']));
        }

        $products = CatalogProduct::query()->active()->whereIn('external_id', $post->products)
            ->get(['id', 'external_id', 'title', 'url', 'image_url'])
            ->sortBy(fn (CatalogProduct $p): int => (int) array_search((string) $p->external_id, $post->products, true))
            ->values();
        $document = new PostDocument($post, $products, (string) (Shop::query()->whereKey($post->shop_id)->value('name') ?? ''));

        try {
            $sent = $this->blog->push($connection, $document->payload(), isset($remote['id']) ? (string) $remote['id'] : null);
        } catch (StoreFeedUnavailable $e) {
            // What it made before stays known, so the next try updates that draft rather than making another.
            return $this->keep($post, array_replace($remote, ['platform' => (string) $connection->platform, 'status' => 'failed', 'error' => $e->reason, 'detail' => mb_substr($e->getMessage(), 0, 300)]));
        }

        return $this->keep($post, [
            'platform' => (string) $connection->platform,
            'id' => $sent['id'],
            'url' => $sent['url'],
            'admin_url' => $sent['admin_url'],
            'status' => 'draft',
        ]);
    }

    /** @param array<string, mixed> $remote */
    private function keep(ImprovementPost $post, array $remote): ImprovementPost
    {
        if (($remote['status'] ?? null) === 'draft') {
            unset($remote['error'], $remote['detail']);
        }

        $post->forceFill(['remote' => ['at' => now()->toAtomString()] + $remote])->save();

        return $post;
    }
}
