<?php

namespace App\Modules\Taxonomy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A value of the store's tag bank: "מדע בדיוני" of the genres, "אבן אדומה" of the stones.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $group_id
 * @property string $key
 * @property string $label_he
 * @property string $label_en
 * @property list<string>|null $words other words a shopper says for it
 * @property string|null $about
 * @property string $status active | merged | retired
 * @property string|null $merged_into
 * @property string|null $parent_id the broader tag of the same group it is part of ("כלכלה התנהגותית" of "כלכלה")
 * @property int $version the bank's version it came in with
 */
class TaxonomyTag extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const ACTIVE = 'active';

    public const MERGED = 'merged';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['words' => 'array', 'version' => 'integer'];
    }

    /** @return BelongsTo<TaxonomyTag, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsTo<TaxonomyGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TaxonomyGroup::class, 'group_id');
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'he' ? $this->label_he : $this->label_en;
    }
}
