<?php

namespace App\Modules\Catalog\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One of a shop's colour names code's words do not know, as it was placed: the shades it is (ColorShade
 * values) and its pattern (FabricPattern), or why it was not.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $name_key
 * @property string $name
 * @property list<string>|null $shades
 * @property string|null $pattern
 * @property string $status placed | unknown | disputed
 * @property string $source model | person
 * @property string|null $models writer · checker
 * @property string|null $run_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CatalogColourName extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const PLACED = 'placed';

    public const UNKNOWN = 'unknown';

    public const DISPUTED = 'disputed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['shades' => 'array'];
    }

    /** A name as it is looked up: lower case, one space. "Ocean  Mist" and "ocean mist" are one name. */
    public static function keyOf(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($name))), 0, 120);
    }

    /**
     * The shop's placed names, as VariantRows reads them.
     *
     * @return array<string, array{shades: list<string>, pattern: string|null}> by name key
     */
    public static function placed(): array
    {
        return self::query()->where('status', self::PLACED)->get(['name_key', 'shades', 'pattern'])
            ->mapWithKeys(fn (self $n): array => [$n->name_key => ['shades' => array_values(array_map('strval', (array) $n->shades)), 'pattern' => $n->pattern]])
            ->all();
    }
}
