<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Actions\CaptionImages;
use App\Modules\Retrieval\Actions\SwitchEmbedder;
use App\Modules\Retrieval\Contracts\PictureContent;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Models\RetrievalQueryVector;
use App\Modules\Retrieval\Support\EmbeddingChoice;
use App\Modules\Retrieval\Support\ModelChoice;
use App\Modules\Retrieval\Support\VectorSearch;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use App\Modules\Tenancy\Models\Shop;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every shop's words moved to another embedding model: the default and the thresholds that depend on it
 * change, the old vectors go, every shop is embedded again. A shop not done yet finds by spelling alone,
 * never by another model's vectors; the next run goes on without paying twice. The words' model can also be
 * a single shop's, and so can each threshold read against it.
 */
final class SwitchEmbedderTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    private const DEFAULTS = 'semantic=0.23,meaning_only=0.47,content=0.38,assistant_site=0.26,min_alternative=0.47';

    private Shop $other;

    /** @var list<array{0: string, 1: string, 2: string|null}> model, text, shop */
    public array $sent = [];

    /** @var (Closure(string, string|null): bool)|null fails the call when true: model, shop */
    public ?Closure $fail = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->shop->update(['slug' => 'gueta']);
        $this->other = Shop::factory()->create(['slug' => 'rocks']);
        $test = $this;

        // A vector per model: 48 numbers for OpenAI's, as many as asked (1024) for Voyage's, so two models' vectors can never pass for one.
        $this->app->instance(Embedder::class, new class($test) implements Embedder
        {
            public function __construct(private readonly SwitchEmbedderTest $test) {}

            public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings
            {
                $shop = app(TenantContext::class)->id();
                if ($this->test->fail !== null && ($this->test->fail)($model, $shop)) {
                    throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
                }
                foreach ($texts as $text) {
                    $this->test->sent[] = [$model, $text, $shop];
                }
                $size = str_starts_with($model, 'voyage') ? ($dimensions ?? 32) : 48;

                return new Embeddings(array_map(function (string $text) use ($size): array {
                    $vector = array_fill(0, $size, 0.0);
                    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $word) {
                        if (mb_strlen($word) > 1) {
                            $vector[crc32($word) % $size] += 1.0;
                        }
                    }

                    return $vector;
                }, array_values($texts)), count($texts) * 10);
            }
        });
        // No model here takes a query apart: the words go as they are, through the embedder above.
        $this->app->instance(ImageEmbedder::class, new class implements ImageEmbedder
        {
            public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings
            {
                throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
            }

            public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
            {
                throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
            }
        });

        $this->product('10', 'מקדחה נטענת 18V', 'מקדחה נטענת חזקה עם סוללה ליתיום ומטען מהיר, לקידוח בעץ ובמתכת.');
        $this->product('11', 'מקדחה נטענת 12V', 'מקדחה נטענת קלה עם סוללה ליתיום, לקידוח בעץ ובמתכת בבית.');
        $this->product('30', 'משקפי מגן', 'משקפי מגן שקופים נגד אבק ושבבים.');
        $this->article('500', 'איך לבחור מקדחה נטענת', "מקדחה נטענת נבחרת לפי מתח הסוללה.\n\n18V לעבודות קשות, 12V לעבודות קלות.", ['10', '11']);
        $this->inOther(fn () => CatalogProduct::query()->create([
            'shop_id' => $this->other->id, 'external_id' => '10', 'type' => 'simple', 'status' => 'publish', 'title' => 'טבעת זהב',
            'price' => '900.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => 'ring',
            'payload' => ['title' => 'טבעת זהב', 'short_description' => '', 'description' => 'טבעת זהב צהוב 14K עם אבן.', 'categories' => [], 'attributes' => [], 'meta' => []],
        ]));

        app(BuildIndex::class)->handle($this->shop->id);
        app(BuildIndex::class)->handle($this->other->id);
        // a wording a shopper typed, kept with today's model
        $this->inShop(fn () => app(SemanticSearch::class)->nearText($this->shop->id, 'מקדחה נטענת', ['product'], 3));
        $this->sent = [];
    }

    public function test_every_shop_moves_the_old_vectors_go_and_measured_shops_keep_their_own_thresholds(): void
    {
        Settings::set('search.meaning_only_min_similarity', 0.4, $this->other->id);

        $this->artisan('retrieval:switch-embedder', ['--price' => '0.12', '--thresholds' => [self::DEFAULTS, 'gueta:semantic=0.24,meaning_only=0.45,min_alternative=0.45', 'rocks:semantic=0.22']])
            ->expectsOutputToContain('rocks search.meaning_only_min_similarity=0.4')
            ->assertSuccessful();

        $this->assertSame(['voyage', 'voyage-4-large', 1024, 0.12], array_map(fn (string $k) => Settings::get($k), SwitchEmbedder::MODEL_KEYS));
        $this->assertSame(0.23, Settings::get('search.semantic_min_similarity'));
        $this->assertSame(0.24, Settings::get('search.semantic_min_similarity', $this->shop->id));
        $this->assertSame(0.45, Settings::get('retrieval.min_alternative_similarity', $this->shop->id));
        $this->assertSame(0.38, Settings::get('search.content_min_similarity', $this->shop->id));
        $this->assertSame(0.22, Settings::get('search.semantic_min_similarity', $this->other->id));
        $this->assertSame(0.47, Settings::get('search.meaning_only_min_similarity', $this->other->id), 'a shop\'s own value made for the old model is dropped');

        foreach ([$this->shop->id, $this->other->id] as $shop) {
            $vectors = app(TenantContext::class)->run($shop, fn () => RetrievalChunk::query()->get()->map(fn (RetrievalChunk $c): array => [$c->embedding_model, count($c->vector() ?? [])])->unique()->values()->all());
            $this->assertSame([['voyage-4-large', 1024]], $vectors, 'every piece embedded again with the new model');
        }
        $this->assertSame(0, app(TenantContext::class)->runUnscoped(fn () => RetrievalQueryVector::query()->where('embedding_model', 'text-embedding-3-small')->count()), 'the old wordings\' vectors are gone');

        $this->sent = [];
        $found = $this->inShop(fn () => app(SemanticSearch::class)->nearText($this->shop->id, 'מקדחה נטענת', ['product'], 3));
        $this->assertContains('11', array_column($found, 'external_id'));
        $this->assertSame([['voyage-4-large', 'מקדחה נטענת', $this->shop->id]], $this->sent, 'the shopper\'s words embedded with the new model');
    }

    public function test_a_shop_stopped_midway_finds_by_spelling_and_the_next_run_goes_on_without_paying_twice(): void
    {
        $this->fail = fn (string $model, ?string $shop): bool => str_starts_with($model, 'voyage') && $shop === $this->other->id;

        $this->artisan('retrieval:switch-embedder', ['--price' => '0.12', '--thresholds' => [self::DEFAULTS]])
            ->expectsOutputToContain('rocks: stopped')
            ->assertFailed();

        $this->assertSame('voyage-4-large', ModelChoice::for('embedding', $this->other->id)->model);
        $this->assertSame(0, $this->inOther(fn () => RetrievalChunk::query()->whereNotNull('embedding')->count()), 'no vector of the old model is left to compare');
        $this->assertSame([], $this->inOther(fn () => app(SemanticSearch::class)->nearText($this->other->id, 'טבעת זהב', ['product'], 3)), 'nothing by meaning, and no error: the search goes on by spelling');
        $this->assertFalse($this->inOther(fn () => app(SemanticSearch::class)->ready()));
        $this->assertNotEmpty($this->inShop(fn () => app(SemanticSearch::class)->nearText($this->shop->id, 'מקדחה נטענת', ['product'], 3)), 'the shop that finished searches by meaning');

        $this->fail = null;
        $this->sent = [];
        $this->artisan('retrieval:switch-embedder', ['--price' => '0.12'])
            ->expectsOutputToContain('only what is left is embedded')
            ->assertSuccessful();

        $this->assertSame([$this->other->id], array_values(array_unique(array_column($this->sent, 2))), 'only the shop left was embedded');
        $this->assertSame('10', $this->inOther(fn () => app(SemanticSearch::class)->nearText($this->other->id, 'טבעת זהב', ['product'], 3))[0]['external_id']);
        $this->assertSame(0.47, Settings::get('search.meaning_only_min_similarity'), 'running again without thresholds keeps them');
    }

    public function test_when_the_new_model_does_not_answer_nothing_changes(): void
    {
        $this->fail = fn (string $model): bool => str_starts_with($model, 'voyage');

        $this->artisan('retrieval:switch-embedder', ['--price' => '0.12', '--thresholds' => [self::DEFAULTS]])
            ->expectsOutputToContain('Nothing changed')
            ->assertFailed();

        $this->assertSame('text-embedding-3-small', Settings::get('retrieval.embedding_model'));
        $this->assertSame(0.3, Settings::get('search.semantic_min_similarity'));
        $this->assertSame(0, $this->inShop(fn () => RetrievalChunk::query()->whereNull('embedding')->count()), 'every vector kept');
        $this->assertNotEmpty($this->inShop(fn () => app(SemanticSearch::class)->nearText($this->shop->id, 'מקדחה נטענת', ['product'], 3)));
    }

    public function test_a_description_s_words_are_embedded_again_without_reading_the_picture_again(): void
    {
        Features::override('retrieval.image_index', true, $this->shop->id);
        Features::override('retrieval.image_captions', true, $this->shop->id);
        $vision = new class implements VisionModel
        {
            public int $calls = 0;

            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls++;

                return new ModelReply(['caption' => 'x', 'words' => []], 1, 1);
            }
        };
        $this->app->instance(VisionModel::class, $vision);
        $this->inShop(fn () => RetrievalImage::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => CatalogProduct::query()->where('external_id', '10')->value('id'), 'external_id' => '10',
            'title' => 'מקדחה נטענת 18V', 'image_url' => 'https://s.test/10.jpg', 'url_hash' => 'u10', 'embedding_model' => 'gemini-embedding-2', 'embedded_at' => now(),
            'caption' => 'מקדחה כחולה עם סוללה', 'caption_words' => ['מקדחה', 'סוללה'], 'captioned_at' => now(),
            'caption_key' => CaptionImages::key('u10', (string) Settings::get('retrieval.caption_model')), 'caption_model' => 'text-embedding-3-small',
            'caption_embedding' => VectorSearch::literal(array_fill(0, 48, 0.5)),
        ]));

        $this->artisan('retrieval:switch-embedder', ['--price' => '0.12', '--thresholds' => [self::DEFAULTS]])->assertSuccessful();

        $image = $this->inShop(fn () => RetrievalImage::query()->sole());
        $this->assertSame(0, $vision->calls, 'the picture is not read again');
        $this->assertSame('voyage-4-large', $image->caption_model);
        $this->assertCount(1024, json_decode((string) $image->caption_embedding, true));
        $this->assertContains(['voyage-4-large', 'מקדחה כחולה עם סוללה מקדחה, סוללה', $this->shop->id], $this->sent);
        $this->assertSame('10', $this->inShop(fn () => app(PictureContent::class)->picturesNearWords($this->shop->id, 'סוללה', 3))[0]['external_id']);
    }

    public function test_a_dry_run_says_what_it_would_cost_and_sends_nothing(): void
    {
        $this->artisan('retrieval:switch-embedder', ['--price' => '0.12', '--thresholds' => [self::DEFAULTS], '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame([], $this->sent);
        $this->assertSame('text-embedding-3-small', Settings::get('retrieval.embedding_model'));
        $this->assertSame(0, $this->inShop(fn () => RetrievalChunk::query()->whereNull('embedding')->count()));

        $left = app(SwitchEmbedder::class)->estimate($this->shop->id, EmbeddingChoice::of('voyage', 'voyage-4-large', 1024, 0.12));
        $this->assertSame($this->inShop(fn () => RetrievalChunk::query()->count()), $left['pieces'], 'every piece, as none has the new model\'s vector');
        $this->assertGreaterThan(0, $left['tokens']);
    }

    public function test_without_every_threshold_or_a_price_nothing_starts(): void
    {
        $this->artisan('retrieval:switch-embedder', ['--price' => '0.12', '--thresholds' => ['semantic=0.23']])->assertFailed();
        $this->artisan('retrieval:switch-embedder', ['--thresholds' => [self::DEFAULTS]])->assertFailed();
        $this->artisan('retrieval:switch-embedder', ['--price' => '0.12', '--thresholds' => [self::DEFAULTS, 'nowhere:semantic=0.2']])->assertFailed();

        $this->assertSame([], $this->sent);
        $this->assertSame('text-embedding-3-small', Settings::get('retrieval.embedding_model'));
    }

    public function test_one_shop_on_another_model_searches_only_its_vectors_while_the_others_stay_on_the_default(): void
    {
        foreach (['retrieval.embedding_provider' => 'voyage', 'retrieval.embedding_model' => 'voyage-4-large', 'retrieval.embedding_dimensions' => 1024] as $key => $value) {
            Settings::set($key, $value, $this->shop->id);
        }

        $this->assertFalse($this->inShop(fn () => app(SemanticSearch::class)->ready()), 'the old vectors do not count for it');
        $this->assertTrue($this->inOther(fn () => app(SemanticSearch::class)->ready()));

        app(BuildIndex::class)->handle($this->shop->id);
        $this->sent = [];
        $this->inShop(fn () => app(SemanticSearch::class)->nearText($this->shop->id, 'סוללה ליתיום', ['product'], 3));
        $this->inOther(fn () => app(SemanticSearch::class)->nearText($this->other->id, 'טבעת', ['product'], 3));

        $this->assertSame([['voyage-4-large', 'סוללה ליתיום', $this->shop->id], ['text-embedding-3-small', 'טבעת', $this->other->id]], $this->sent);
        $this->assertSame(['voyage-4-large'], $this->inShop(fn () => RetrievalChunk::query()->distinct()->pluck('embedding_model')->all()));
        $this->assertSame(['text-embedding-3-small'], $this->inOther(fn () => RetrievalChunk::query()->distinct()->pluck('embedding_model')->all()));
        $drill = $this->inShop(fn () => RetrievalChunk::query()->where('source', 'product')->where('external_id', '10')->value('source_id'));
        $this->assertSame('11', $this->inShop(fn () => app(SemanticSearch::class)->similarTo('product', $drill, ['product'], 1))[0]['external_id']);
    }

    public function test_every_threshold_of_the_words_model_is_the_shop_s_own(): void
    {
        foreach (SwitchEmbedder::THRESHOLDS as $key) {
            Settings::set($key, 0.11, $this->shop->id);
            $this->assertSame(0.11, Settings::get($key, $this->shop->id), $key);
            $this->assertNotSame(0.11, Settings::get($key, $this->other->id), $key);
        }
    }

    private function inOther(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->other->id, $callback);
    }
}
