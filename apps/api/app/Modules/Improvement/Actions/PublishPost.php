<?php

namespace App\Modules\Improvement\Actions;

use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Str;

/**
 * A published post is a guide of the store's own: served by the platform at its own address,
 * and among the store's content, where the search, the widget and the assistant find it like
 * any page of the site. Unpublished, it leaves the content and the address answers nothing.
 * Callers are inside the shop's tenant.
 */
final class PublishPost
{
    public function publish(ImprovementPost $post, string $title, string $body): void
    {
        $post->forceFill([
            'title' => mb_substr(trim($title), 0, 200),
            'body' => trim($body),
            'status' => ImprovementPost::PUBLISHED,
            'published_at' => $post->published_at ?? now(),
        ])->save();

        // A post that lives on the store's own site has no address here: the store's content brings it in once the
        // store publishes it, so the search does not hold it twice.
        if (! $post->hosted()) {
            $this->leaveContent($post);

            return;
        }

        $shop = Shop::query()->findOrFail($post->shop_id);
        $image = $post->products === [] ? null : CatalogProduct::query()->active()->where('external_id', $post->products[0])->value('image_url');
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(Str::markdown($post->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]))));

        CatalogContent::query()->updateOrCreate(
            ['shop_id' => $post->shop_id, 'type' => CatalogContent::POST, 'external_id' => $post->contentId()],
            [
                'title' => $post->title,
                'url' => route('improvement.guide', ['shop' => $shop->slug, 'post' => $post->id]),
                'image_url' => is_string($image) ? $image : null,
                'excerpt' => mb_substr($text, 0, 300),
                'body' => $text,
                'product_external_ids' => $post->products,
                'hash' => hash('sha256', $post->title."\n".$post->body),
                'source_updated_at' => now(),
                'synced_at' => now(),
                'removed_at' => null,
            ],
        );

        event(new CatalogUpdated($post->shop_id));
    }

    public function unpublish(ImprovementPost $post): void
    {
        if ($post->status !== ImprovementPost::PUBLISHED) {
            return;
        }

        $post->forceFill(['status' => ImprovementPost::UNPUBLISHED])->save();
        $this->leaveContent($post);
    }

    private function leaveContent(ImprovementPost $post): void
    {
        if (CatalogContent::query()->where('external_id', $post->contentId())->whereNull('removed_at')->update(['removed_at' => now()]) > 0) {
            event(new CatalogUpdated($post->shop_id));
        }
    }
}
