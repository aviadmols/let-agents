<?php

namespace App\Modules\Shopify\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Shopify\Contracts\KeepsShopperData;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shopify\Mail\ShopperDataRequestMail;
use App\Modules\Shopify\Models\ShopperDataReport;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A shopper asked the store, through Shopify, for what it keeps about them (customers/data_request). The report is
 * made at once: every module that keeps shopper data (KeepsShopperData, 'shopify.shopper_data') says what it keeps
 * about them, and the whole is kept for the store's owners, who download it from the notice in their panel and
 * send it on. The owners are emailed when the platform sends email. The same request sent again is one report.
 *
 * A module that fails fails the request, so Shopify sends it again later: a report with a part missing is not one.
 */
final class ReportShopperData
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(ShopperDataRequested $request): ?ShopperDataReport
    {
        $shop = Shop::query()->find($request->shopId);

        if ($shop === null) {
            return null;
        }

        [$report, $created] = $this->tenant->run($shop->id, function () use ($shop, $request): array {
            $sections = [];

            foreach (app()->tagged(KeepsShopperData::TAG) as $source) {
                foreach ($source->about($request) as $name => $rows) {
                    $sections[$name] = [...($sections[$name] ?? []), ...$rows];
                }
            }

            ksort($sections);
            $ref = $request->requestId === '' ? null : mb_substr($request->requestId, 0, 64);
            $report = $ref === null ? new ShopperDataReport : ShopperDataReport::query()->firstOrNew(['request_ref' => $ref]);
            $created = ! $report->exists;

            $report->forceFill([
                'shop_id' => $shop->id,
                'request_ref' => $ref,
                'customer_ref' => preg_match('/^[0-9]{1,20}$/', $request->customerId) === 1 ? $request->customerId : null,
                'email_hash' => ShopperDataReport::emailHash($request->email, $shop->id),
                'report' => [
                    'store' => (string) $shop->name,
                    'domain' => (string) $shop->domain,
                    'requested_at' => now()->toIso8601String(),
                    'shopper' => array_filter([
                        'shopify_customer_id' => $request->customerId,
                        'email' => $request->email,
                        'phone' => $request->phone,
                    ], fn (string $value): bool => $value !== ''),
                    'kept' => $sections,
                ],
            ])->save();

            return [$report, $created];
        });

        Log::info('A shopper asked for what the store keeps about them', ['shop' => $shop->id, 'report' => $report->id, 'sections' => array_keys($report->report['kept'])]);

        if ($created) {
            $this->tellOwners($shop, $report);
        }

        return $report;
    }

    /** The shop's owners, in their panel's language, when the platform sends email; one that fails is logged. */
    private function tellOwners(Shop $shop, ShopperDataReport $report): void
    {
        // the "log" mailer writes the message to the log instead of sending it: nobody would be told
        if (in_array((string) config('mail.default'), ['log', ''], true)) {
            return;
        }

        $link = route('shopify.data_report', ['report' => $report->id]);

        foreach ($shop->belongsToMany(User::class, 'shop_user')->wherePivot('role', ShopRole::Owner->value)->get() as $owner) {
            try {
                Mail::to($owner->email)->locale($owner->preferredLocale() ?? app()->getLocale())->send(new ShopperDataRequestMail((string) $shop->name, $report->created_at, $link));
            } catch (Throwable $e) {
                Log::warning('A shopper data request email was not sent', ['to' => $owner->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
