<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\RecordVisit;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/visit/{site}: the steps of a visit, from the search window on the store's pages.
 * Sent as text/plain (no preflight), with sendBeacon when the page is left; nothing reads the answer.
 */
final class VisitController
{
    private const MAX_BYTES = 32_000;

    public function __invoke(Request $request, RecordVisit $record, string $site): Response|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        if ($request->headers->has('Origin') && ! $connection->allowsOrigin($request->header('Origin'))) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $raw = (string) $request->getContent();

        if (strlen($raw) > self::MAX_BYTES) {
            return response()->json(['error' => 'too_large'], 413);
        }

        $result = $record->handle($connection, $request, $raw);

        return $result['problems'] !== []
            ? response()->json(['error' => 'invalid', 'problems' => $result['problems']], 422)
            : response()->noContent(202);
    }
}
