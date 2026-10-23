<?php

namespace App\Modules\Shopify\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What the store keeps about one shopper who asked for it through Shopify (customers/data_request), for the
 * store's owners to download and send on. Encrypted at rest; erased with the shopper when they ask to be.
 *
 * @property string $id
 * @property string $shop_id
 * @property string|null $request_ref Shopify's id of the request
 * @property string|null $customer_ref the Shopify customer id
 * @property string|null $email_hash
 * @property array<string, mixed> $report
 * @property Carbon|null $downloaded_at
 * @property int|null $downloaded_by
 * @property Carbon $created_at
 */
class ShopperDataReport extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'shopify_data_reports';

    protected $guarded = ['id'];

    protected $hidden = ['report'];

    /** The code an email is found again by, salted by the shop: the address itself is only in the report. */
    public static function emailHash(string $email, string $shopId): ?string
    {
        $email = mb_strtolower(trim($email));

        return $email === '' ? null : hash('sha256', $shopId.'|'.$email);
    }

    protected function casts(): array
    {
        return [
            'report' => 'encrypted:array',
            'downloaded_at' => 'datetime',
        ];
    }
}
