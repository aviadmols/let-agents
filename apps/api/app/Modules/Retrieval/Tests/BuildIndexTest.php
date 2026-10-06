<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Support\Chunker;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The index: products, pages and posts, and what the orders say, cut into pieces and given
 * vectors — and only the pieces that changed ever go to the model again.
 */
final class BuildIndexTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->product('10', 'מקדחה נטענת 18V', 'מקדחה נטענת חזקה עם סוללה ליתיום ומטען מהיר, לקידוח בעץ ובמתכת.');
        $this->product('11', 'מקדחה נטענת 12V', 'מקדחה נטענת קלה עם סוללה ליתיום, לקידוח בעץ ובמתכת בבית.');
        $this->product('20', 'סט מקדחים לבטון', 'סט מקדחים מוקשים לקידוח בבטון ובלוקים, חמש מידות.');
        $this->product('30', 'משקפי מגן', 'משקפי מגן שקופים נגד אבק ושבבים.');
        $this->article('500', 'איך לבחור מקדחה נטענת', "מקדחה נטענת נבחרת לפי מתח הסוללה.\n\n18V מתאים לעבודות קשות, 12V לעבודות קלות בבית.", ['10', '11']);
        $this->order(['10', '20']);
        $this->order(['10', '20', '30'], 'history', '-14 months');
    }

    public function test_every_source_is_cut_into_pieces_and_given_a_vector(): void
    {
        $run = app(BuildIndex::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $sources = $run->output['sources'];
        $this->assertSame(4, $sources['product']['documents']);
        $this->assertSame(1, $sources['content']['documents']);
        $this->assertSame(3, $sources['purchases']['documents'], 'one per product that sold: the drill, the bits, the goggles');
        $this->assertSame(0, $run->output['embedding']['pending']);
        $this->assertSame('text-embedding-3-small', $this->embedder->model, 'the model the settings name');
        $this->assertGreaterThan(0, (float) $run->cost_usd, 'the cost is recorded on the run');

        $chunks = $this->inShop(fn () => RetrievalChunk::query()->get());
        $this->assertSame([], $chunks->whereNull('embedding')->pluck('id')->all(), 'every piece has a vector');
        $this->assertSame([48], $chunks->pluck('dimensions')->unique()->values()->all());

        $purchases = $chunks->where('source', 'purchases')->firstWhere('external_id', '10');
        $this->assertStringContainsString('סט מקדחים לבטון', $purchases->text, 'the drill\'s orders name what was bought with it');
        $this->assertStringContainsString('Bought in about 2 orders', $purchases->text, 'history counts as much as live orders');

        $product = $chunks->where('source', 'product')->firstWhere('external_id', '10');
        $this->assertStringStartsWith('מקדחה נטענת 18V', $product->text, 'every piece starts with its title');
        $this->assertStringNotContainsString('499', $product->text, 'price changes daily and stays out of the text');
    }

    public function test_a_second_run_sends_only_what_changed_and_forgets_what_the_store_removed(): void
    {
        app(BuildIndex::class)->handle($this->shop->id);
        $calls = $this->embedder->calls;

        $again = app(BuildIndex::class)->handle($this->shop->id);
        $this->assertSame($calls, $this->embedder->calls, 'nothing changed, nothing sent');
        $this->assertSame(0, $again->output['embedding']['embedded']);

        $this->embedder->texts = [];
        $this->inShop(function (): void {
            $drill = CatalogProduct::query()->where('external_id', '11')->sole();
            $drill->update(['payload' => ['description' => 'מקדחה נטענת קלה עם שתי סוללות.'] + $drill->payload]);
            CatalogProduct::query()->where('external_id', '30')->update(['removed_at' => now()]);
        });

        $run = app(BuildIndex::class)->handle($this->shop->id);

        $this->assertSame(1, $run->output['sources']['product']['changed'], 'only the edited product');
        $this->assertSame(0, $run->output['sources']['content']['changed']);
        // The goggles are gone, so what the drill's and the bits' orders say changed too.
        $this->assertSame(2, $run->output['sources']['purchases']['changed']);
        $this->assertSame(
            ['מקדחה נטענת 12V', 'מקדחה נטענת 18V', 'סט מקדחים לבטון'],
            collect($this->embedder->texts)->map(fn (string $t): string => strtok($t, "\n"))->unique()->sort()->values()->all(),
            'and nothing else went to the model',
        );
        $this->assertSame(0, $this->inShop(fn () => RetrievalChunk::query()->where('external_id', '30')->count()), 'a product the store stopped publishing leaves the index');
    }

    public function test_a_new_model_rebuilds_every_vector_and_the_old_ones_are_never_compared(): void
    {
        app(BuildIndex::class)->handle($this->shop->id);
        Settings::set('retrieval.embedding_model', 'text-embedding-3-large');

        $this->assertFalse($this->inShop(fn () => app(SemanticSearch::class)->ready()), 'vectors of another model do not count');

        $run = app(BuildIndex::class)->handle($this->shop->id);

        $this->assertSame($run->output['embedding']['embedded'], $this->inShop(fn () => RetrievalChunk::query()->count()));
        $this->assertSame(['text-embedding-3-large'], $this->inShop(fn () => RetrievalChunk::query()->distinct()->pluck('embedding_model')->all()));
        $this->assertTrue($this->inShop(fn () => app(SemanticSearch::class)->ready()));
    }

    public function test_similar_products_are_found_by_meaning_without_a_model_call(): void
    {
        app(BuildIndex::class)->handle($this->shop->id);
        $calls = $this->embedder->calls;

        $drill = $this->inShop(fn () => CatalogProduct::query()->where('external_id', '10')->sole());
        $similar = $this->inShop(fn () => app(SemanticSearch::class)->similarTo('product', $drill->id, ['product'], 3));

        $this->assertSame('11', $similar[0]['external_id'], 'the other cordless drill is nearest');
        $this->assertNotContains($drill->id, array_column($similar, 'source_id'), 'never itself');
        $this->assertSame($calls, $this->embedder->calls, 'comparing stored vectors costs nothing');

        $guides = $this->inShop(fn () => app(SemanticSearch::class)->similarTo('product', $drill->id, ['content'], 3));
        $this->assertSame('500', $guides[0]['external_id'], 'and the guide about choosing one');
    }

    public function test_the_spending_cap_stops_the_vectors_but_not_the_index(): void
    {
        $this->app->instance(SpendGuard::class, new class implements SpendGuard
        {
            public function assertCanSpend(float $estimatedUsd): void
            {
                throw new SpendCapReached(6.0, $estimatedUsd, 6.0);
            }

            public function spentThisMonth(): float
            {
                return 6.0;
            }

            public function cap(): float
            {
                return 6.0;
            }
        });

        $run = app(BuildIndex::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, 'a full month is not a failure');
        $this->assertSame('spend_cap', $run->output['embedding']['stopped']);
        $this->assertSame(0, $this->embedder->calls);
        $this->assertGreaterThan(0, $run->output['embedding']['pending'], 'the pieces wait for next month');
    }

    public function test_long_hebrew_text_is_cut_at_sentences_without_breaking_a_letter(): void
    {
        $sentence = 'המקדחה מגיעה עם שתי סוללות ליתיום ומטען מהיר, ומתאימה לעבודה רצופה של יום שלם באתר.';
        $pieces = Chunker::split('מקדחה נטענת', str_repeat($sentence.' ', 30), 400);

        $this->assertGreaterThan(5, count($pieces));

        foreach ($pieces as $piece) {
            $this->assertLessThanOrEqual(400, mb_strlen($piece));
            $this->assertStringStartsWith("מקדחה נטענת\n", $piece);
            $this->assertTrue(mb_check_encoding($piece, 'UTF-8'), 'no letter cut in half');
            $this->assertStringEndsWith('באתר.', $piece, 'cut between sentences');
        }

        $this->assertSame(['כותרת'], Chunker::split('כותרת', '   ', 400), 'a document with only a title is still one piece');
    }
}
