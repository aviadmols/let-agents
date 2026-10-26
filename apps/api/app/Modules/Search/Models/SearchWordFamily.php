<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A store's word families, as read against its words of words_hash (WriteWordFamilies).
 *
 * @property string $id
 * @property string $shop_id
 * @property string $words_hash
 * @property list<list<string>> $families
 * @property int $prompt_version
 * @property string|null $model
 */
class SearchWordFamily extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['families' => 'array', 'prompt_version' => 'integer'];
    }
}
