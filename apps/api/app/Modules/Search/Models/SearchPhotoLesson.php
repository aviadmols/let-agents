<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One photo the team marked wrong, and what it shows after the stronger model looked again.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $photo_hash
 * @property string|null $seen
 * @property string|null $team
 * @property string $object
 * @property string|null $main_id
 * @property string|null $main_title
 */
class SearchPhotoLesson extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];
}
