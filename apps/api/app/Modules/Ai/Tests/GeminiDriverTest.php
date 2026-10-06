<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ListsProviderModels;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\ProviderCheckFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Gemini as a driver: pictures and words in one request, the key in a header, never in the address. */
final class GeminiDriverTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AIzaSyTestTestTestTestTestTestTestTest';

    protected function setUp(): void
    {
        parent::setUp();

        $provider = AiProvider::query()->create(['provider' => AiProviderName::Gemini, 'api_key' => self::KEY]);
        $provider->status = ProviderStatus::Connected; // A new key starts untested; the check would mark it so.
        $provider->save();
    }

    public function test_pictures_and_words_go_in_one_batch_request(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['embeddings' => [['values' => [0.1, 0.2]], ['values' => [0.3, 0.4]]]])]);

        $embeddings = app(ImageEmbedder::class)->embedImages(AiProviderName::Gemini, 'gemini-embedding-2', [
            ['mime' => 'image/jpeg', 'data' => 'abc'],
            ['mime' => 'image/png', 'data' => 'def'],
        ], 768);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $embeddings->vectors);
        $this->assertSame(2 * 258, $embeddings->inputTokens);

        Http::assertSent(function (Request $request): bool {
            $first = $request['requests'][0];

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents'
                && $request->hasHeader('x-goog-api-key', self::KEY)
                && ! str_contains($request->url(), self::KEY)
                && $first['model'] === 'models/gemini-embedding-2'
                && $first['output_dimensionality'] === 768
                && $first['content']['parts'][0]['inline_data'] === ['mime_type' => 'image/jpeg', 'data' => base64_encode('abc')];
        });
    }

    public function test_a_shoppers_words_are_marked_as_a_search(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['embeddings' => [['values' => [1.0]]]])]);

        app(ImageEmbedder::class)->embedTextsForImages(AiProviderName::Gemini, 'gemini-embedding-2', ['חולצת פסים']);

        Http::assertSent(fn (Request $request): bool => $request['requests'][0]['content']['parts'][0]['text'] === 'task: search result | query: חולצת פסים'
            && ! isset($request['requests'][0]['output_dimensionality']));
    }

    public function test_a_refusal_or_a_short_answer_is_a_failed_call(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'bad key']], 400)
            ->push(['embeddings' => [['values' => [1.0]]]])]);

        foreach ([1, 2] as $attempt) {
            try {
                app(ImageEmbedder::class)->embedImages(AiProviderName::Gemini, 'gemini-embedding-2', [['mime' => 'image/jpeg', 'data' => 'a'], ['mime' => 'image/jpeg', 'data' => 'b']]);
                $this->fail("call {$attempt} should have failed");
            } catch (ModelCallFailed $e) {
                $this->assertSame(ModelCallFailed::PROVIDER_ERROR, $e->reason);
            }
        }
    }

    public function test_openai_cannot_embed_pictures(): void
    {
        $this->expectException(ModelCallFailed::class);

        app(ImageEmbedder::class)->embedImages(AiProviderName::OpenAi, 'text-embedding-3-small', [['mime' => 'image/jpeg', 'data' => 'a']]);
    }

    public function test_the_panel_lists_gemini_models_with_the_key(): void
    {
        Http::fake(['generativelanguage.googleapis.com/v1beta/models*' => Http::sequence()
            ->push(['models' => [['name' => 'models/gemini-embedding-2', 'displayName' => 'Gemini Embedding 2']]])
            ->push(['error' => ['message' => 'API key not valid']], 400)]);

        $models = app(ListsProviderModels::class)->list(AiProviderName::Gemini, self::KEY);
        $this->assertSame([['id' => 'gemini-embedding-2', 'name' => 'Gemini Embedding 2', 'created_at' => null]], $models);

        $this->expectException(ProviderCheckFailed::class);
        app(ListsProviderModels::class)->list(AiProviderName::Gemini, 'wrong');
    }
}
