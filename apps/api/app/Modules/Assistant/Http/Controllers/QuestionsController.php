<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Contracts\SuggestsQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/widget/{site}/questions?id=<page id>&type=product|content&locale=he
 *
 * What the question box shows before the shopper types: questions to tap (the ones shoppers ask
 * most about this product, then common ones) and answers earlier shoppers got. Answers only;
 * nothing about who asked.
 */
final class QuestionsController
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    private const RECENT = 5;

    public function __invoke(Request $request, TenantContext $tenant, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin !== null && ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $id = (string) $request->query('id');
        $locale = $request->query('locale') === 'en' ? 'en' : 'he';
        $onArticle = $request->query('type') === 'content';

        if (! preg_match(self::ID_PATTERN, $id)) {
            return response()->json(['error' => 'invalid_page'], 422);
        }

        $shopId = $connection->shop_id;

        if (! Features::enabled($onArticle ? 'assistant.on_content' : 'assistant.on_products', $shopId)) {
            return response()->json(['data' => ['enabled' => false, 'suggested' => [], 'recent' => []]]);
        }

        $data = $tenant->run($shopId, function () use ($shopId, $id, $locale, $onArticle): array {
            $page = $onArticle
                ? CatalogContent::query()->active()->where('external_id', $id)->first()
                : CatalogProduct::query()->whereNull('removed_at')->where('external_id', $id)->first();
            $answered = $page === null ? collect() : AssistantAnswer::query()
                ->where($onArticle ? 'content_id' : 'product_id', $page->id)
                ->where('outcome', AssistantAnswer::ANSWERED)
                ->where('status', AssistantAnswer::SHOWN)
                ->shared()
                ->get(['question', 'answer', 'source', 'asked_count', 'last_asked_at']);

            $limit = (int) Settings::get('assistant.suggested_questions', $shopId);
            $suggested = $page === null
                ? []
                : app(SuggestsQuestions::class)->for($page->id, $onArticle, $locale, $limit);

            return [
                'enabled' => true,
                'suggested' => $suggested,
                'recent' => $answered->sortByDesc('last_asked_at')->take(self::RECENT)
                    ->map(fn (AssistantAnswer $a): array => array_filter(['question' => $a->question, 'answer' => (string) $a->answer, 'source' => $a->source]))->values()->all(),
            ];
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
