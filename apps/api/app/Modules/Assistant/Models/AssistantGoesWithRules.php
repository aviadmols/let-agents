<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * How a store focuses what the assistant offers to go with a product (the goes-with mechanism), one row a shop, written on
 * the merchant's settings page (GoesWithSettings) and read by code at answer time:
 *
 * - `pairs`: which of the store's own kinds may or may not be offered with which: [{a, b, allow}], by the vocabulary's type
 *   keys, read both ways (rings with earrings yes, rings with socks no). With any allowed pair for a kind, only its
 *   allowed partners are offered; a denied pair is never offered.
 * - `never`: what is never offered as a match or a complement: [{scope: type|category|vendor, value}].
 *
 * @property string $id
 * @property string $shop_id
 * @property list<array{a: string, b: string, allow: bool}> $pairs
 * @property list<array{scope: string, value: string}> $never
 */
class AssistantGoesWithRules extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const SCOPES = ['type', 'category', 'vendor'];

    protected $table = 'assistant_goes_with_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pairs' => 'array', 'never' => 'array'];
    }

    /**
     * The shop's rules, read in its tenant; empty lists when it wrote none.
     *
     * @return array{pairs: list<array{a: string, b: string, allow: bool}>, never: list<array{scope: string, value: string}>}
     */
    public static function of(string $shopId): array
    {
        $row = self::query()->where('shop_id', $shopId)->first();

        return [
            'pairs' => array_values(array_filter((array) ($row?->pairs ?? []), fn ($p): bool => is_array($p) && isset($p['a'], $p['b']))),
            'never' => array_values(array_filter((array) ($row?->never ?? []), fn ($n): bool => is_array($n) && isset($n['scope'], $n['value']))),
        ];
    }
}
