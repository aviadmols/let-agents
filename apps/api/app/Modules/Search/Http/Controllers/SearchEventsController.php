<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Actions\CountSearch;
use App\Modules\Search\Http\StorefrontSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/search/{site}/events — sent with navigator.sendBeacon, so the body is JSON as
 * text/plain. Up to ten events:
 *
 *   {"type":"search","q":"מקיטא","results":11}           the shopper paused over suggestions
 *   {"type":"click","q":"מקיטא","id":"p:1203","title":"…"}  a result was opened
 *
 * Queries and titles only: nothing about the shopper.
 */
final class SearchEventsController
{
    private const MAX_EVENTS = 10;

    public function __invoke(Request $request, CountSearch $count, TenantContext $tenant, string $site): JsonResponse|Response
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $body = json_decode((string) $request->getContent(), true);
        $events = is_array($body['events'] ?? null) ? array_slice($body['events'], 0, self::MAX_EVENTS) : [];

        $tenant->run($connection->shop_id, function () use ($events, $count, $connection): void {
            foreach ($events as $event) {
                if (! is_array($event) || ! is_string($event['q'] ?? null)) {
                    continue;
                }

                match ($event['type'] ?? null) {
                    'search' => $count->search($connection->shop_id, $event['q'], (int) ($event['results'] ?? 0)),
                    'click' => $count->click($connection->shop_id, $event['q'], (string) ($event['id'] ?? ''), (string) ($event['title'] ?? '')),
                    default => null,
                };
            }
        });

        return response()->noContent();
    }
}
