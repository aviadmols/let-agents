<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Contracts\PlatformStoreBlog;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\Client\ConnectionException;

/**
 * A WordPress store's blog, through the plugin's one write route (POST /let-agents/v1/posts):
 * the token, and a signature over the request with the same key, so only Let Agents can write.
 * The plugin makes the draft, its picture, the SEO plugin's fields and the questions' schema.
 */
final class PluginBlog implements PlatformStoreBlog
{
    public const ROUTE = '/let-agents/v1/posts';

    public function platform(): string
    {
        return 'woocommerce';
    }

    public function push(StoreConnection $connection, array $post, ?string $remoteId): array
    {
        $body = (string) json_encode(['post' => $post, 'id' => $remoteId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            [$response] = PluginHttp::post($connection, self::ROUTE, $body, 60);
        } catch (ConnectionException) {
            throw new StoreFeedUnavailable('unreachable');
        }

        // The route arrived with plugin 0.8.0: an older plugin has no way to take a post.
        if ($response->status() === 404 && in_array($response->json('code'), ['rest_no_route', null], true)) {
            throw new StoreFeedUnavailable('plugin_outdated', 404);
        }

        $failure = PluginHttp::failure($response);
        if ($failure !== null) {
            throw new StoreFeedUnavailable($failure, $response->status(), (string) $response->json('message', ''));
        }

        $id = (string) $response->json('id', '');
        if ($id === '') {
            throw new StoreFeedUnavailable('unexpected_response', $response->status());
        }

        return ['id' => $id, 'url' => $response->json('url'), 'admin_url' => $response->json('edit_url')];
    }
}
