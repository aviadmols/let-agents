<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Modules\Assistant\Actions\AnswerQuestion;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\NoAnswer;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/widget/{site}/ask   {"id": "<page id>", "type": "product"|"content", "question": "...", "vid": "anon-...", "locale": "he"}
 *
 * Sent as text/plain from the storefront so the browser sends no preflight; the body is JSON.
 */
final class AskController
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

    public function __invoke(Request $request, AnswerQuestion $answer, string $site): JsonResponse
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
        $id = is_array($body) ? (string) ($body['id'] ?? '') : '';
        $question = is_array($body) ? (string) ($body['question'] ?? '') : '';
        $visitor = is_array($body) ? (string) ($body['vid'] ?? '') : '';
        $locale = is_array($body) && ($body['locale'] ?? 'he') === 'en' ? 'en' : 'he';
        // A widget still cached from before guides could be asked about sends no type.
        $pageType = is_array($body) && ($body['type'] ?? 'product') === 'content' ? 'content' : 'product';

        if (! preg_match(self::ID_PATTERN, $id) || ! preg_match(self::VISITOR_PATTERN, $visitor) || $question === '') {
            return response()->json(['error' => 'invalid_question'], 422);
        }

        // The visitor only counts toward a daily limit, as a hash salted per shop, like analytics.
        $result = $answer->handle($connection->shop_id, $pageType, $id, $question, hash('sha256', $connection->shop_id.'|'.$visitor), $locale);

        // Not an answer: what the storefront offers instead, as the shop chose it (the team, leaving details, or nothing).
        if (isset($result['outcome']) && $result['outcome'] !== AssistantAnswer::ANSWERED) {
            $result['no_answer'] = NoAnswer::offer($connection->shop_id);
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }
}
