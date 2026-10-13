<?php

namespace App\Modules\Shoppers\Http\Controllers;

use App\Core\Facades\Features;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shoppers\Actions\SignUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/widget/{site}/signup   {"vid": "anon-…", "contact": "0501234567", "consent": true, "locale": "he"}
 *
 * Sent as text/plain from the storefront so the browser sends no preflight; the body is JSON.
 */
final class SignUpController
{
    private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

    /** The page a question was asked on, in the shape the store uses for its ids. */
    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    public function __invoke(Request $request, SignUp $signUp, string $site): JsonResponse
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
        $waitingFor = self::waitingFor(is_array($body) ? $body : []);

        // Leaving a way to be told one answer is not the same as signing up for what the shop
        // sends everyone, so the two are switched on separately.
        $needs = $waitingFor === null ? 'shoppers.signup' : 'shoppers.callbacks';

        if (! Features::enabled($needs, $connection->shop_id)) {
            return response()->json(['error' => 'not_enabled'], 403);
        }

        $visitor = is_array($body) ? (string) ($body['vid'] ?? '') : '';
        $contact = is_array($body) ? (string) ($body['contact'] ?? '') : '';
        $consent = is_array($body) && ($body['consent'] ?? false) === true;
        $locale = is_array($body) && ($body['locale'] ?? 'he') === 'en' ? 'en' : 'he';

        if (! preg_match(self::VISITOR_PATTERN, $visitor)) {
            return response()->json(['error' => 'invalid_visitor'], 422);
        }

        $result = $signUp->handle(
            $connection->shop_id,
            hash('sha256', $connection->shop_id.'|'.$visitor),
            $contact,
            $consent,
            $locale,
            $waitingFor,
        );

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    /**
     * The question they want answered, when this is a "tell me when you know" rather than a
     * sign-up. Anything malformed is treated as a plain sign-up rather than half a callback.
     *
     * @param  array<string, mixed>  $body
     * @return array{question: string, type: string, id: string, name: string|null}|null
     */
    private static function waitingFor(array $body): ?array
    {
        $question = trim((string) ($body['question'] ?? ''));
        // Asked in the search chat: the id is the question's own.
        $type = in_array($body['type'] ?? '', ['content', 'search'], true) ? (string) $body['type'] : 'product';
        $id = (string) ($body['id'] ?? '');

        return $question === '' || ! preg_match(self::ID_PATTERN, $id)
            ? null
            : ['question' => $question, 'type' => $type, 'id' => $id, 'name' => self::name($body['name'] ?? null)];
    }

    /** A name as people write one: letters, spaces, an apostrophe or a hyphen; anything else is no name. */
    private static function name(mixed $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', is_string($name) ? $name : ''));

        return $name !== '' && mb_strlen($name) <= 60 && preg_match("/^[\\p{L}][\\p{L}\\p{M} '\"׳״.-]*$/u", $name) === 1 ? $name : null;
    }
}
