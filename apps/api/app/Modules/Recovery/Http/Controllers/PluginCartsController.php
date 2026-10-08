<?php

namespace App\Modules\Recovery\Http\Controllers;

use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Recovery\Actions\RecordCapturedCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/plugin/{site}/carts — the store's plugin reports a cart a shopper left their
 * email for, after it kept it as an order waiting for payment. Signed like every plugin call.
 */
final class PluginCartsController
{
    public function __invoke(Request $request, RecordCapturedCart $record, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $valid = $connection->verifyPluginSignature(
            (string) $request->header('X-LetAgents-Timestamp'),
            $request->method(),
            $request->getRequestUri(),
            (string) $request->getContent(),
            (string) $request->header('X-LetAgents-Signature'),
        );

        if (! $valid) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['error' => 'not_json'], 422);
        }

        $result = $record->handle($connection, $payload);

        return $result['problems'] === []
            ? response()->json(['stored' => $result['stored']], 202)
            : response()->json(['error' => 'invalid', 'problems' => $result['problems']], 422);
    }
}
