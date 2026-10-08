<?php

namespace App\Modules\Recovery\Models;

use App\Core\Facades\Settings;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A cart a shopper left their email for before paying.
 *
 *   open       waiting: paid, or reported once the shop's wait has passed
 *   converted  an order from the same order or the same browser came in after it
 *   reported   not paid; the report says what the shopper looked for and how they got here
 *
 * @property string $id
 * @property string $shop_id
 * @property string $platform
 * @property string $order_ref
 * @property string|null $store_order_id
 * @property string|null $admin_url
 * @property string $email
 * @property string $email_hash
 * @property string $email_masked
 * @property string|null $consent_text
 * @property Carbon $consented_at
 * @property string|null $visitor_hash
 * @property list<array{product_id: string, variation_id: string|null, quantity: int, total: string}> $items
 * @property string $total
 * @property string|null $currency
 * @property list<array{q: string, at: string|null}>|null $searches
 * @property string $status
 * @property Carbon $captured_at
 * @property Carbon|null $converted_at
 * @property array<string, mixed>|null $report
 * @property int|null $report_version
 * @property bool|null $report_checked
 * @property Carbon|null $reported_at
 */
class RecoveryCart extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use MassPrunable;

    public const OPEN = 'open';

    public const CONVERTED = 'converted';

    public const REPORTED = 'reported';

    protected $table = 'recovery_carts';

    protected $guarded = ['id'];

    protected $hidden = ['email'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'items' => 'array',
            'searches' => 'array',
            'report' => 'array',
            'report_checked' => 'boolean',
            'consented_at' => 'datetime',
            'captured_at' => 'datetime',
            'converted_at' => 'datetime',
            'reported_at' => 'datetime',
        ];
    }

    /** Carts are kept for recovery.keep_days, then dropped with their email. */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()->where('captured_at', '<', now()->subDays((int) Settings::get('recovery.keep_days')));
    }
}
