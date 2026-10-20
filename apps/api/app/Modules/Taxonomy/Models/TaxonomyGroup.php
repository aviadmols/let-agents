<?php

namespace App\Modules\Taxonomy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of tag a store's products are told apart by: a genre, an occasion, a material.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $key
 * @property string $label_he
 * @property string $label_en
 * @property string $kind inferred (a theme or style a reader may judge) | factual (what it is made of, measures, holds)
 * @property bool $multiple
 * @property bool $searchable its tags help the search find products
 * @property bool $filter its tags are shown to shoppers as a filter
 * @property bool $recommend its tags help choose what to show next to a product
 * @property string|null $about
 * @property list<string>|null $scope the store's categories it applies to (genre to books, size to clothes); empty: all
 * @property bool $from_picture its tags may be read from a product's picture: a colour, a shape, a style; never a stone, a karat or a material
 * @property int $position
 * @property int $version the bank's version it came in with
 */
class TaxonomyGroup extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const INFERRED = 'inferred';

    public const FACTUAL = 'factual';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['multiple' => 'boolean', 'searchable' => 'boolean', 'filter' => 'boolean', 'recommend' => 'boolean', 'from_picture' => 'boolean', 'scope' => 'array', 'position' => 'integer', 'version' => 'integer'];
    }

    /**
     * Whether it applies to something in these of the store's categories: always, when it has no scope
     * or the thing has no categories (a post).
     *
     * @param  list<string>  $categories
     */
    public function appliesTo(array $categories): bool
    {
        $scope = array_map('mb_strtolower', (array) $this->scope);

        return $scope === [] || $categories === [] || array_intersect($scope, array_map('mb_strtolower', $categories)) !== [];
    }

    /** @return HasMany<TaxonomyTag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(TaxonomyTag::class, 'group_id');
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'he' ? $this->label_he : $this->label_en;
    }
}
