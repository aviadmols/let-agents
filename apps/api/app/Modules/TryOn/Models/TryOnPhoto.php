<?php

namespace App\Modules\TryOn\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A shopper's photo, and what the gate found in it.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $visitor_hash
 * @property string $photo_hash
 * @property string|null $path
 * @property int $width
 * @property int $height
 * @property string $status
 * @property string|null $reject_code
 * @property array<string, mixed>|null $gate
 * @property list<string>|null $usable classes the photo can serve
 * @property string $cost_usd
 * @property Carbon|null $consent_at
 * @property Carbon|null $expires_at
 */
class TryOnPhoto extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const OK = 'ok';

    public const REJECTED = 'rejected';

    protected $table = 'try_on_photos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['gate' => 'array', 'usable' => 'array', 'consent_at' => 'datetime', 'expires_at' => 'datetime', 'width' => 'integer', 'height' => 'integer'];
    }
}
