<?php

namespace App\Modules\Taxonomy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Two products the team kept apart (never shown as alike) or put together (always alike), by a rule it approved.
 * Kept in both directions, one row each, so a lookup is by one product.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string $other_id
 * @property string $kind apart | alike
 * @property string|null $rule_id
 */
class TaxonomyPair extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const APART = 'apart';

    public const ALIKE = 'alike';

    protected $guarded = ['id'];

    /**
     * What one product is kept apart from and put together with.
     *
     * @return array{apart: list<string>, alike: list<string>} product ids
     */
    public static function of(string $productId): array
    {
        $pairs = ['apart' => [], 'alike' => []];

        foreach (self::query()->where('product_id', $productId)->orderBy('id')->get(['other_id', 'kind']) as $pair) {
            $pairs[$pair->kind === self::ALIKE ? 'alike' : 'apart'][] = (string) $pair->other_id;
        }

        return $pairs;
    }
}
