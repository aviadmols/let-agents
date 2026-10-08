<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One search by photo: the reduced photo, what was seen, what the shopper got, and the team's
 * verdict when they gave one.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $photo_hash
 * @property string|null $photo
 * @property string|null $thumb
 * @property string|null $object
 * @property float|null $sure
 * @property string|null $doubt
 * @property bool $second
 * @property string|null $main
 * @property list<array<string, mixed>> $tags
 * @property list<array<string, mixed>> $results
 * @property int $total
 * @property string|null $verdict
 * @property string|null $expected
 * @property Carbon|null $marked_at
 * @property string|null $checked_main
 * @property bool|null $checked_right
 * @property Carbon|null $checked_at
 * @property Carbon $created_at
 */
class SearchPhotoAsk extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const RIGHT = 'right';

    public const WRONG = 'wrong';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sure' => 'float', 'second' => 'boolean', 'tags' => 'array', 'results' => 'array', 'total' => 'integer',
            'marked_at' => 'datetime', 'checked_right' => 'boolean', 'checked_at' => 'datetime',
        ];
    }

    /** The photo's bytes, for a second reading. */
    public function photoBytes(): ?string
    {
        return $this->photo === null ? null : (base64_decode($this->photo, true) ?: null);
    }
}
