<?php

namespace App\Modules\Shopify\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Shopify\Models\ShopperDataReport;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /shopify/data-reports/{report}: what the store keeps about a shopper who asked for it, as one JSON file, for
 * the store's owners alone (the report names a person): not its team, not an operator. The first download is noted,
 * and the notice in the panel says it was taken. Signed out (the link in the owners' email), the panel's sign-in comes
 * first and returns here.
 */
final class ShopperDataReportController
{
    public function __invoke(string $report, TenantContext $tenant): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return redirect()->guest((string) Filament::getPanel(User::MERCHANT_PANEL)->getLoginUrl());
        }

        $model = $tenant->runUnscoped(fn (): ?ShopperDataReport => ShopperDataReport::query()->find($report));

        abort_unless($model !== null && $user->roleIn($model->shop_id) === ShopRole::Owner, 403);

        if ($model->downloaded_at === null) {
            $tenant->run($model->shop_id, fn () => $model->forceFill(['downloaded_at' => now(), 'downloaded_by' => $user->id])->save());
        }

        Log::info('A shopper data report was downloaded', ['shop' => $model->shop_id, 'report' => $model->id, 'by' => $user->id]);

        $json = (string) json_encode($model->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="shopper-data-'.$model->created_at->format('Y-m-d').'-'.mb_strtolower(substr($model->id, -6)).'.json"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
