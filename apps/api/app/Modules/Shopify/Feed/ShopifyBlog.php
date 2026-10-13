<?php

namespace App\Modules\Shopify\Feed;

use App\Modules\Connections\Contracts\PlatformStoreBlog;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApi;

/**
 * A Shopify store's blog: the post becomes a hidden article on the store's first blog (one is
 * made when the store has none), with its picture, tags and summary, its SEO title and
 * description in the fields Shopify reads for them (global.title_tag, global.description_tag),
 * and its questions in a metafield (let_agents.faq) the theme's app embed prints as FAQPage.
 * The owner publishes it from Shopify. Sent again, it is updated while still hidden; once the
 * owner published it, it is theirs and left as it is.
 *
 * Needs the write_content scope; a store that has not approved it is told so.
 */
final class ShopifyBlog implements PlatformStoreBlog
{
    private const BLOGS = 'query { blogs(first: 20) { nodes { id handle } } }';

    private const CREATE_BLOG = <<<'GQL'
        mutation ($blog: BlogCreateInput!) {
          blogCreate(blog: $blog) { blog { id handle } userErrors { field message code } }
        }
        GQL;

    private const FIND = 'query ($id: ID!) { article(id: $id) { id isPublished handle blog { handle } } }';

    private const CREATE = <<<'GQL'
        mutation ($article: ArticleCreateInput!) {
          articleCreate(article: $article) { article { id handle blog { handle } } userErrors { field message code } }
        }
        GQL;

    private const UPDATE = <<<'GQL'
        mutation ($id: ID!, $article: ArticleUpdateInput!) {
          articleUpdate(id: $id, article: $article) { article { id handle blog { handle } } userErrors { field message code } }
        }
        GQL;

    public function __construct(private readonly ShopifyApi $api) {}

    public function platform(): string
    {
        return 'shopify';
    }

    public function push(StoreConnection $connection, array $post, ?string $remoteId): array
    {
        $install = ShopifyInstall::query()->where('shop_id', $connection->shop_id)->first();

        if ($install === null || ! $install->installed()) {
            throw new StoreFeedUnavailable('not_installed');
        }

        // Granted scopes are known from the token: without write_content, nothing is asked.
        if ($install->scopes !== null && ! in_array('write_content', array_map('trim', explode(',', (string) $install->scopes)), true)) {
            throw new StoreFeedUnavailable('missing_scope');
        }

        $fields = $this->fields($post);

        if ($remoteId !== null && $remoteId !== '') {
            $found = (array) ($this->api->query($install, self::FIND, ['id' => $remoteId])['article'] ?? []);

            if (($found['id'] ?? null) !== null) {
                if (($found['isPublished'] ?? false) === true) {
                    return $this->answer($install, $found);
                }

                $updated = (array) ($this->api->query($install, self::UPDATE, ['id' => $remoteId, 'article' => $fields])['articleUpdate'] ?? []);
                $this->refuseOnErrors($updated);

                return $this->answer($install, (array) ($updated['article'] ?? $found));
            }
        }

        $created = (array) ($this->api->query($install, self::CREATE, ['article' => ['blogId' => $this->blogId($install)] + $fields])['articleCreate'] ?? []);
        $this->refuseOnErrors($created);

        return $this->answer($install, (array) ($created['article'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function fields(array $post): array
    {
        $metafields = array_values(array_filter([
            ($post['seo_title'] ?? '') !== '' ? ['namespace' => 'global', 'key' => 'title_tag', 'type' => 'single_line_text_field', 'value' => (string) $post['seo_title']] : null,
            ($post['meta_description'] ?? '') !== '' ? ['namespace' => 'global', 'key' => 'description_tag', 'type' => 'single_line_text_field', 'value' => (string) $post['meta_description']] : null,
            ($post['faq'] ?? []) !== [] ? ['namespace' => 'let_agents', 'key' => 'faq', 'type' => 'json', 'value' => (string) json_encode(array_values($post['faq']), JSON_UNESCAPED_UNICODE)] : null,
        ]));

        return array_filter([
            'title' => (string) $post['title'],
            'body' => (string) $post['html'],
            'summary' => (string) ($post['excerpt'] ?? ''),
            'author' => ['name' => (string) ($post['author'] ?? '')],
            'isPublished' => false,
            'tags' => array_values((array) ($post['tags'] ?? [])),
            'image' => is_array($post['image'] ?? null) ? ['url' => (string) $post['image']['url'], 'altText' => (string) ($post['image']['alt'] ?? '')] : null,
            'metafields' => $metafields,
        ], fn ($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    private function blogId(ShopifyInstall $install): string
    {
        $blogs = (array) ($this->api->query($install, self::BLOGS)['blogs']['nodes'] ?? []);

        if ($blogs !== []) {
            // The store's news blog when it has one, as themes link it; otherwise its first.
            foreach ($blogs as $blog) {
                if (($blog['handle'] ?? '') === 'news') {
                    return (string) $blog['id'];
                }
            }

            return (string) $blogs[0]['id'];
        }

        $created = (array) ($this->api->query($install, self::CREATE_BLOG, ['blog' => ['title' => 'Blog']])['blogCreate'] ?? []);
        $this->refuseOnErrors($created);

        return (string) ($created['blog']['id'] ?? throw new StoreFeedUnavailable('unexpected_response'));
    }

    /** @param array<string, mixed> $result */
    private function refuseOnErrors(array $result): void
    {
        $errors = array_filter(array_map(fn ($e): string => trim((string) ($e['message'] ?? '')), (array) ($result['userErrors'] ?? [])));

        if ($errors !== []) {
            throw new StoreFeedUnavailable('rejected', null, implode(' · ', $errors));
        }
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array{id: string, url: string|null, admin_url: string|null}
     */
    private function answer(ShopifyInstall $install, array $article): array
    {
        $id = (string) ($article['id'] ?? '');

        if ($id === '') {
            throw new StoreFeedUnavailable('unexpected_response');
        }

        $store = preg_replace('/\.myshopify\.com$/', '', (string) $install->shop_domain);
        $numeric = (string) preg_replace('/\D/', '', (string) strrchr($id, '/'));

        return [
            'id' => $id,
            // A hidden article has no public address yet; this is where it will be once published.
            'url' => isset($article['handle'], $article['blog']['handle']) ? 'https://'.$install->shop_domain.'/blogs/'.$article['blog']['handle'].'/'.$article['handle'] : null,
            'admin_url' => "https://admin.shopify.com/store/{$store}/content/articles/{$numeric}",
        ];
    }
}
