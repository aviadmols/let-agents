<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Actions\ExportShopData;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /account/{shop}/export: everything the shop holds as one ZIP (ExportShopData), for its owner (or an operator).
 * A plain address rather than a panel button, so a large file goes to the browser as a file, never through Livewire.
 */
final class ExportShopDataController
{
    public function __invoke(string $shop, ExportShopData $export): BinaryFileResponse
    {
        $user = Auth::user();
        $model = Shop::query()->where('slug', $shop)->first();

        abort_unless($user instanceof User && $model !== null && $user->managesShop($model), 403);

        Log::info('A shop\'s data was downloaded', ['shop' => $model->id, 'by' => $user->id]);

        return response()->download($export->handle($model), 'lets-'.$model->slug.'-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
