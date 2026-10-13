<?php

namespace App\Modules\Sms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The account the platform sends SMS from: one per provider, the operator's, never a shop's.
 *
 * @property string $id
 * @property string $provider "019"
 * @property string $username
 * @property string $token decrypted on read
 * @property string|null $token_hint safe to show
 * @property string $sender at most 11 English letters or digits
 * @property bool $active
 * @property string|null $last_error
 * @property Carbon|null $last_sent_at
 */
class SmsAccount extends Model
{
    use HasUlids;

    protected $fillable = ['provider', 'username', 'token', 'token_hint', 'sender', 'active'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'active' => 'boolean', 'last_sent_at' => 'datetime'];
    }
}
