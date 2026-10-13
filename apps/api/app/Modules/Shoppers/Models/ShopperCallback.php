<?php

namespace App\Modules\Shoppers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone left a way to reach them because the assistant had no answer.
 *
 * The question is here in the open, because the team has to read it to answer it. The contact is
 * not: it lives on the identity, encrypted, and the team sees only the masked form until they
 * choose to reach out.
 *
 * @property int $id
 * @property string $shop_id
 * @property int $identity_id
 * @property string|null $name the name they gave (the search chat asks for it)
 * @property string $question
 * @property string $page_type product, content, or search (asked in the search chat; page_id is the question's id)
 * @property string $page_id
 * @property Carbon|null $answered_at
 * @property Carbon $created_at
 */
class ShopperCallback extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'name' => 'encrypted'];
    }

    /** @return BelongsTo<ShopperIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(ShopperIdentity::class, 'identity_id');
    }
}
