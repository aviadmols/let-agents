<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A search handed to a shopper's own AI (ShareSearch): the snapshot behind /s/{token}.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $token
 * @property string $fingerprint
 * @property string $query
 * @property array<string, mixed> $snapshot
 * @property int $opens
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class SearchShare extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use MassPrunable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'opens' => 'integer', 'expires_at' => 'datetime'];
    }

    /** Gone a day after it expires: the link says "expired" for that day, then nothing. */
    public function prunable()
    {
        return static::query()->withoutGlobalScopes()->where('expires_at', '<', now()->subDay());
    }
}
