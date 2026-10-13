<?php

namespace App\Modules\Sms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One SMS sent, or tried: the shop, why, the number masked, and what the provider said.
 *
 * @property int $id
 * @property string|null $shop_id
 * @property string $provider
 * @property string $purpose
 * @property string $phone_masked
 * @property bool $ok
 * @property string|null $error
 * @property string|null $provider_ref
 * @property Carbon|null $created_at
 */
class SmsMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ok' => 'boolean'];
    }
}
