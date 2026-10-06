<?php

namespace App\Modules\Retrieval\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Enums\MatchKind;
use App\Modules\Retrieval\Enums\MatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product the matching model picked for another, and code's verdict on the pick.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $request_id
 * @property string $product_id
 * @property string|null $related_product_id null when the model named a product code never offered
 * @property MatchKind $kind
 * @property MatchStatus $status
 * @property string|null $rejected_because a short code: unknown, unavailable, not_similar, both_kinds, over_limit, self
 * @property int $position the model's own order within the kind
 * @property string|null $reason the model's words, for the team; never shown to a shopper
 * @property array<string, mixed>|null $signals what code knew about the pair when it offered it
 */
class RetrievalMatch extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => MatchKind::class,
            'status' => MatchStatus::class,
            'position' => 'integer',
            'signals' => 'array',
        ];
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'related_product_id');
    }
}
