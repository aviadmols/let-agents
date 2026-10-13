<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Http\Visit;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantSearchAsk;
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
 *   {"type":"add_to_cart","q":"מקיטא","id":"p:1203","title":"…","path":"/"}  added to the cart from
 *                                                           the results or the assistant's picks;
 *                                                           "via":"page" from the page of a product
 *                                                           opened from the search, within a week
 *
 * With them, "vid": the anonymous id the storefront scripts keep, only so an order by the same
 * visitor is attributed to the search; stored as a hash salted per shop. Nothing else about the
 * shopper.
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
        $visitor = is_string($body['vid'] ?? null) ? $body['vid'] : null;
        $visit = Visit::from($request, is_array($body['ctx'] ?? null) ? $body['ctx'] : []);

        $tenant->run($connection->shop_id, function () use ($events, $count, $connection, $visitor, $visit): void {
            foreach ($events as $event) {
                if (! is_array($event) || ! is_string($event['q'] ?? null)) {
                    continue;
                }

                match ($event['type'] ?? null) {
                    'search' => $count->search($connection->shop_id, $event['q'], (int) ($event['results'] ?? 0), $visit),
                    'click' => $count->click($connection->shop_id, $event['q'], (string) ($event['id'] ?? ''), (string) ($event['title'] ?? ''), $visit),
                    'add_to_cart' => $count->added($connection->shop_id, $event['q'], (string) ($event['id'] ?? ''), (string) ($event['title'] ?? ''), $visitor, (string) ($event['path'] ?? '/')),
                    'ask_whatsapp' => self::onAsk($event, fn (AssistantSearchAsk $ask) => $ask->forceFill(['whatsapp_clicked' => true])->save()),
                    'ask_pick' => self::onAsk($event, function (AssistantSearchAsk $ask) use ($event): void {
                        $id = (string) ($event['id'] ?? '');
                        // Only one of the products it picked counts as acting on its pick.
                        if (in_array($id, array_column((array) $ask->picks, 'external_id'), true)) {
                            $ask->forceFill(['picked' => array_values(array_unique([...(array) $ask->picked, $id]))])->save();
                        }
                    }),
                    default => null,
                };
            }
        });

        return response()->noContent();
    }

    /** The question this event belongs to, in this shop, asked today or yesterday. */
    private static function onAsk(array $event, \Closure $then): void
    {
        $id = (string) ($event['ask_id'] ?? '');
        $ask = preg_match('/^[0-9a-z]{26}$/i', $id) === 1
            ? AssistantSearchAsk::query()->whereKey(strtolower($id))->where('created_at', '>=', now()->subDays(2))->first()
            : null;

        if ($ask !== null) {
            $then($ask);
        }
    }
}
