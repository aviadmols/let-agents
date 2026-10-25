<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A word shoppers typed that the store does not write, and the store's word it means, or none (term null), as read
 * against the store's words of words_hash (WordMeanings).
 *
 * @property string $id
 * @property string $shop_id
 * @property string $word
 * @property string|null $term
 * @property string $words_hash
 * @property string|null $model
 */
class SearchWordMeaning extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];
}
