<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Modules\Search\Actions\ShareSearch;
use App\Modules\Search\Http\StorefrontSite;
use App\Modules\Search\Support\AgentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/search/{site}/share {q, ids[]}
 *
 * A shopper's search handed to their own AI: a short link to the snapshot (ShareSearch), the
 * words to send with it, and where to open them. Nothing about the shopper is sent or kept, and
 * no model is asked.
 */
final class ShareController
{
    /** Where each AI opens with the words already typed. The words go where {q} is. */
    public const OPEN = [
        'chatgpt' => 'https://chatgpt.com/?q={q}',
        'claude' => 'https://claude.ai/new?q={q}',
        'perplexity' => 'https://www.perplexity.ai/search?q={q}',
    ];

    public function __invoke(Request $request, ShareSearch $share, string $site): JsonResponse
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        if (! Features::enabled('search.storefront', $connection->shop_id)
            || (! Features::enabled('search.ai_share', $connection->shop_id) && ! Features::enabled('search.share_results', $connection->shop_id))) {
            return response()->json(['error' => 'share_off'], 404);
        }

        // Sent as text/plain from the storefront, so no preflight is needed: the body is JSON either way.
        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : $request->all();
        $query = trim((string) ($body['q'] ?? ''));
        $ids = array_map('strval', array_filter((array) ($body['ids'] ?? []), 'is_scalar'));

        if ($query === '' && $ids === []) {
            return response()->json(['error' => 'nothing_to_share'], 422);
        }

        $saved = $share->handle($connection, $query, $ids);
        $locale = in_array($body['locale'] ?? null, ['he', 'en'], true) ? (string) $body['locale'] : (string) ($connection->shop?->content_locale ?? 'he');
        $url = AgentCatalog::publicUrl('s/'.$saved->token);
        // One sentence and one link: the JSON holds the rest (the instructions, the tasks, the commands,
        // and every category and page of the whole store written out, since an AI's browser opens
        // only addresses it has seen).
        $words = __('search::share.ask', ['store' => (string) ($connection->shop?->name ?? ''), 'url' => $url.'?format=json'], $locale);

        return response()->json([
            'url' => $url,
            'prompt' => $words,
            'open' => array_map(fn (string $at): string => str_replace('{q}', rawurlencode($words), $at), self::OPEN),
        ], 201, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
