<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check of a product's missing details on the web, with what a person decided about each.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string|null $vocabulary_id
 * @property list<string> $attribute_keys
 * @property string $fingerprint
 * @property string $certainty
 * @property array<string, array<string, mixed>> $results
 * @property array<string, mixed>|null $verifier
 * @property list<string>|null $problems
 * @property int|null $requested_by
 * @property string|null $run_id
 * @property string $cost_usd
 */
class EnrichmentWebLookup extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const CERTAIN = 'certain';

    public const LIKELY = 'likely';

    public const CONFLICTING = 'conflicting';

    public const NOT_FOUND = 'not_found';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attribute_keys' => 'array', 'results' => 'array', 'verifier' => 'array', 'problems' => 'array'];
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'product_id');
    }
}
