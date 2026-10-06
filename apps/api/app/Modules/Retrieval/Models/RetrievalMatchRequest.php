<?php

namespace App\Modules\Retrieval\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Enums\MatchRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The last time the matching model was asked about one product: what code offered it and what
 * it answered. One row per product, replaced when the product is asked about again.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property MatchRequestStatus $status
 * @property string $input_hash
 * @property list<array{ref: string, product_id: string, title: string, sources: list<string>, signals: array<string, mixed>}> $candidates
 * @property array<string, mixed>|null $answer
 * @property string|null $error
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $prompt_version
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd
 * @property Carbon|null $asked_at
 */
class RetrievalMatchRequest extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MatchRequestStatus::class,
            'candidates' => 'array',
            'answer' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'asked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'product_id');
    }

    /** @return HasMany<RetrievalMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(RetrievalMatch::class, 'request_id')->orderBy('kind')->orderBy('position');
    }
}
