<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildShopReport;
use App\Modules\Analytics\Actions\RecordOrder;
use App\Modules\Analytics\Actions\RecordOrderHistory;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Requests from the store's Rega plugin, signed with a key derived from its access token:
 *
 *   POST /api/v1/plugin/{site}/orders    an order was placed (no customer data)
 *   POST /api/v1/plugin/{site}/orders/history   a page of past orders, sent once (same shape)
 *   GET  /api/v1/plugin/{site}/reports   the report the plugin shows in WordPress
 */
final class PluginOrdersController
{
    public function store(Request $request, RecordOrder $record, string $site): JsonResponse
    {
        $connection = $this->authorize($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['error' => 'not_json'], 422);
        }

        $result = $record->handle($connection, $payload);

        return $result['problems'] === []
            ? response()->json(['stored' => $result['stored'], 'assisted' => $result['assisted'] ?? false], 202)
            : response()->json(['error' => 'invalid', 'problems' => $result['problems']], 422);
    }

    public function history(Request $request, RecordOrderHistory $record, string $site): JsonResponse
    {
        $connection = $this->authorize($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['error' => 'not_json'], 422);
        }

        $result = $record->handle($connection, $payload);

        return $result['problems'] === []
            ? response()->json(['received' => $result['received'], 'stored' => $result['stored']], 202)
            : response()->json(['error' => 'invalid', 'problems' => $result['problems']], 422);
    }

    public function report(Request $request, BuildShopReport $report, string $site): JsonResponse
    {
        $connection = $this->authorize($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        return response()
            ->json(['data' => $report->handle($connection->shop_id, (int) $request->query('days', 30))])
            ->header('Cache-Control', 'no-store');
    }

    private function authorize(Request $request, string $site): StoreConnection|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $valid = $connection->verifyPluginSignature(
            (string) $request->header('X-Rega-Timestamp'),
            $request->method(),
            $request->getRequestUri(),
            (string) $request->getContent(),
            (string) $request->header('X-Rega-Signature'),
        );

        return $valid ? $connection : response()->json(['error' => 'bad_signature'], 401);
    }
}
