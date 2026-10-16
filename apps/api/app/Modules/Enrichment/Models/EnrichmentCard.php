<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One product's, page's or the store's card: everything known about it in one JSON a model reads
 * first. See Support\Cards (the shape) and Actions\BuildCards.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $kind product, content or store
 * @property string $subject_id
 * @property array<string, mixed> $card
 * @property string $hash
 * @property Carbon $built_at
 */
class EnrichmentCard extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const PRODUCT = 'product';

    public const CONTENT = 'content';

    public const STORE = 'store';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'card' => 'array',
            'built_at' => 'datetime',
        ];
    }
}
