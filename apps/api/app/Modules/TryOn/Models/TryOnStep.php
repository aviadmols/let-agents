<?php

namespace App\Modules\TryOn\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of a photo's check or of a try, exactly as it ran: what went in, the prompt as sent, what
 * came back, the pictures it read or made, the model, the tokens, the time and the cost.
 *
 * @property string $id
 * @property string $shop_id
 * @property string|null $photo_id
 * @property string|null $job_id
 * @property string $step
 * @property int $attempt
 * @property string $status
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null $prompt
 * @property string|null $provider
 * @property string|null $model
 * @property int $tokens_in
 * @property int $tokens_out
 * @property string $seconds
 * @property string $cost_usd
 * @property array<string, string>|null $images role => path
 * @property string|null $error
 */
class TryOnStep extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const STEPS = ['code_gate', 'vision_gate', 'prompt', 'generate', 'check'];

    protected $table = 'try_on_steps';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['input' => 'array', 'output' => 'array', 'images' => 'array', 'attempt' => 'integer', 'tokens_in' => 'integer', 'tokens_out' => 'integer'];
    }
}
