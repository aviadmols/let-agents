<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One product's type put to the auditor (AuditProductTypes): what code doubted, what was asked (its fingerprint), and
 * the verdict with what code did about it. The same fingerprint is never asked again: a verdict is reused.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string $fingerprint
 * @property list<string>|null $reasons
 * @property string|null $code_type
 * @property string|null $type a type key of the bank, "none", or null while asked
 * @property int|null $confidence
 * @property string|null $why
 * @property string|null $model
 * @property string $status
 * @property list<string>|null $problems
 * @property string|null $batch_id
 * @property string|null $run_id
 * @property Carbon|null $answered_at
 */
class EnrichmentTypeAudit extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Sent, no answer yet. */
    public const ASKED = 'asked';

    /** The model named another type of the bank, surely enough: the code type was replaced. */
    public const APPLIED = 'applied';

    /** The model said no type of the bank fits, surely enough: the code type was taken off. */
    public const REMOVED = 'removed';

    /** The model agreed with code, surely enough: nothing changed, and the type carries its verdict. */
    public const KEPT = 'kept';

    /** Under the confidence threshold: nothing changed; the verdict is kept for the operator. */
    public const HELD = 'held';

    /** The reply broke its schema, answered for another product or named a type not in the bank: nothing was mended. */
    public const REJECTED = 'rejected';

    /** The answer that was said: a stored verdict a later run applies again without asking. */
    public const ANSWERED = [self::APPLIED, self::REMOVED, self::KEPT, self::HELD];

    /** The model's word for "no type of the bank is what this is". */
    public const NONE = 'none';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'problems' => 'array',
            'confidence' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'product_id');
    }

    /** @return BelongsTo<EnrichmentBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(EnrichmentBatch::class, 'batch_id');
    }
}
