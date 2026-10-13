<?php

namespace App\Modules\Connections\Contracts;

use App\Modules\Connections\Models\StoreConnection;

/**
 * A post sent to the store's own blog, always as a draft the store owner publishes from their own
 * site. Sent again, the same post is updated while it is still a draft there.
 *
 * $post: title, html, excerpt, seo_title, meta_description, focus_keyword, tags, faq [{q, a}],
 * image {url, alt}|null, author, post_id (ours).
 */
interface StoreBlog
{
    /**
     * @param  array<string, mixed>  $post
     * @return array{id: string, url: string|null, admin_url: string|null}
     *
     * @throws StoreFeedUnavailable when the store cannot take it; its reason says why
     */
    public function push(StoreConnection $connection, array $post, ?string $remoteId): array;
}
