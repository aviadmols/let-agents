<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What a store learned from its shoppers' searches (LearnFromSearches), rewritten every night: "boost", a product (value)
 * first for a search (query), by weight; "no_chip", a word (value) shoppers take off as a chip, read no more as one.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $kind
 * @property string $query
 * @property string $value
 * @property float $weight
 * @property int $visitors
 */
class SearchLearned extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'search_learned';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weight' => 'float', 'visitors' => 'integer'];
    }
}
