<?php

namespace App\Modules\Retrieval\Tests;

use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Contracts\ChunkExperiments;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Support\InMemoryChunkExperiments;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The store's pages cut at another size, for measuring only: embedded in memory once per size,
 * accounted, and never written to the index.
 */
final class ChunkExperimentsTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->product('10', 'מקדחה נטענת 18V', 'מקדחה נטענת חזקה לקידוח בעץ ובמתכת.');
        $body = implode("\n\n", array_map(fn (int $i): string => "פסקה {$i}: מקדחה נטענת נבחרת לפי מתח הסוללה, המומנט ומשקל הכלי. ".str_repeat('הסבר נוסף על הכלי. ', 10), range(1, 6)));
        $this->article('500', 'איך לבחור מקדחה נטענת', $body, ['10']);
        app(BuildIndex::class)->handle($this->shop->id);
    }

    public function test_pages_cut_smaller_give_more_pieces_and_the_index_is_untouched(): void
    {
        $chunks = $this->inShop(fn () => RetrievalChunk::query()->orderBy('id')->get(['id', 'text', 'embedding_model'])->toArray());
        $experiments = app(ChunkExperiments::class);

        $small = $this->inShop(fn () => $experiments->near($this->shop->id, 'מקדחה נטענת', 300, ['content'], 10));
        $large = $this->inShop(fn () => $experiments->near($this->shop->id, 'מקדחה נטענת', 2000, ['content'], 10));

        $this->assertNotEmpty($small);
        $this->assertSame(['content'], array_values(array_unique(array_column($small, 'source'))), 'only the sources asked for');
        $this->assertLessThanOrEqual(2, count($small), 'two pieces of one document at most');
        $this->assertLessThan(mb_strlen($large[0]['text']), mb_strlen($small[0]['text']));
        $this->assertSame($chunks, $this->inShop(fn () => RetrievalChunk::query()->orderBy('id')->get(['id', 'text', 'embedding_model'])->toArray()), 'nothing written to the index');
    }

    public function test_each_size_is_embedded_once_and_accounted(): void
    {
        $experiments = app(ChunkExperiments::class);
        $this->inShop(fn () => $experiments->near($this->shop->id, 'מקדחה', 500, ['content'], 5));
        $calls = $this->embedder->calls;
        $this->inShop(fn () => $experiments->near($this->shop->id, 'סוללה', 500, ['content'], 5));

        $this->assertSame($calls + 1, $this->embedder->calls, 'only the new wording; the pieces are kept');
        $run = Run::query()->where('agent', InMemoryChunkExperiments::AGENT)->sole();
        $this->assertGreaterThan(0, $run->input_tokens);
        $this->assertGreaterThan(0, $experiments->spent());
    }

    public function test_products_keep_their_stored_pieces(): void
    {
        $rows = $this->inShop(fn () => app(ChunkExperiments::class)->near($this->shop->id, 'מקדחה נטענת', 300, ['product', 'content'], 10));

        $this->assertContains('10', array_column(array_filter($rows, fn (array $r): bool => $r['source'] === 'product'), 'external_id'));
        $this->assertSame(1, Run::query()->where('agent', InMemoryChunkExperiments::AGENT)->count());
    }
}
