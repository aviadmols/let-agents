<?php

namespace App\Modules\Catalog\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An article, guide or page the store shares, as plain text.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $type
 * @property string $external_id
 * @property string $title
 * @property string|null $url
 * @property string|null $image_url
 * @property string|null $excerpt
 * @property string|null $body
 * @property array<string, list<string>>|null $terms
 * @property list<string>|null $product_external_ids
 * @property Carbon|null $source_updated_at
 * @property string $hash
 * @property Carbon|null $synced_at
 * @property Carbon|null $removed_at
 */
class CatalogContent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'catalog_content';

    /** The content types a store feed gives: its posts (guides) and its pages. */
    public const POST = 'post';

    public const PAGE = 'page';

    /** What the store says about itself, gathered for the assistant: never a page of the site, never in search results. */
    public const KNOWLEDGE = 'knowledge';

    /** External ids of content the platform wrote for the store itself; a sync never removes them. */
    public const OWN_PREFIX = 'la:';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'product_external_ids' => 'array',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** Words in a page's title that say it is one of the store's policies. */
    public const POLICY_WORDS = '/מדיניות|החזר|ביטול|משלוח|אחריות|תקנון|פרטיות|החלפ|policy|refund|return|shipping|terms|warranty|privacy/iu';

    /** A page of the store's own rules: a platform policy, or a page whose title says so. */
    public function isPolicy(): bool
    {
        return $this->type === self::PAGE
            && (str_starts_with((string) $this->external_id, 'policy-') || preg_match(self::POLICY_WORDS, (string) $this->title) === 1);
    }

    /**
     * Which is read first: the store's policies (returns, delivery, warranty), then its other
     * pages, then its posts. 0 is first.
     */
    public function importance(): int
    {
        return match (true) {
            $this->isPolicy() => 0,
            $this->type === self::PAGE => 1,
            default => 2,
        };
    }

    /** @param Builder<CatalogContent> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * Pages a shopper opens on the store's own site: not those the platform serves at its own address
     * (a post Lets wrote and the team published, at /g/{shop}/{post}), which take the shopper away
     * from the store. They stay the store's content for the assistant to answer from.
     *
     * @param  Builder<CatalogContent>  $query
     */
    public function scopeOnTheSite(Builder $query): void
    {
        $app = parse_url((string) config('app.url'));
        $host = is_array($app) ? (string) ($app['host'] ?? '') : '';

        if ($host === '') {
            return;
        }

        // the address as the platform's links carry it: its host, and its port when it has one
        $ours = $host.(isset($app['port']) ? ':'.$app['port'] : '');
        $query->where(fn (Builder $q) => $q->whereNull('url')->orWhere('url', 'not like', '%://'.$ours.'/%'));
    }
}
