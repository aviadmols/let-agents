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

    /** @param Builder<CatalogContent> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }
}
