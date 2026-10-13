<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/search/{site}/ask   {"question": "...", "vid": "anon-...", "locale": "he", "products": ["<shown ids>"], "previous": ["<ids>"], "page": 1}
 *
 * A question typed in the store's search box, about the whole site. Sent as text/plain from the
 * storefront so the browser sends no preflight; the body is JSON. Called only when the shopper
 * pressed Enter or asked, never while typing.
 */
final class SiteAskController
{
    private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

    public function __invoke(Request $request, AnswerSiteQuestion $answer, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin === null || ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $body = json_decode((string) $request->getContent(), true);
        $question = is_array($body) ? (string) ($body['question'] ?? '') : '';
        $visitor = is_array($body) ? (string) ($body['vid'] ?? '') : '';
        $locale = is_array($body) && ($body['locale'] ?? 'he') === 'en' ? 'en' : 'he';
        // The products the search showed with the question: the assistant picks only from them.
        $products = self::ids(is_array($body) ? ($body['products'] ?? null) : null);
        // In the drawer's conversation, the products the turn before showed: "מה הכי זול?" is about them.
        $previous = self::ids(is_array($body) ? ($body['previous'] ?? null) : null);
        // Asked from a product page: the products are the page's own, and the question is about them.
        $page = is_array($body) && ! empty($body['page']);

        if (! preg_match(self::VISITOR_PATTERN, $visitor) || trim($question) === '') {
            return response()->json(['error' => 'invalid_question'], 422);
        }

        // The visitor only counts toward a daily limit, as a hash salted per shop, like analytics.
        $result = $answer->handle($connection->shop_id, $question, hash('sha256', $connection->shop_id.'|'.$visitor), $locale, $products, $previous, $page);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    /** @return list<string> product ids as the storefront sends them, at most AnswerSiteQuestion::MAX_PRODUCTS */
    private static function ids(mixed $list): array
    {
        return array_values(array_filter(
            array_map('strval', array_slice(is_array($list) ? $list : [], 0, AnswerSiteQuestion::MAX_PRODUCTS)),
            fn (string $id): bool => preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id) === 1,
        ));
    }
}
