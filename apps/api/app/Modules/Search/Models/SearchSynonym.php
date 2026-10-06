<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A word shoppers type that means something the shop's products say.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $term
 * @property string $means
 * @property string $origin
 */
class SearchSynonym extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];
}
