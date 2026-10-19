<?php

namespace App\Modules\Retrieval\Tests;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A post's categories as the store sends them, words or whole ({id, name}): indexed by their names, never an error that stops the index. */
final class ContentTermsTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    public function test_terms_sent_whole_are_read_by_their_names(): void
    {
        $this->buildShop();
        $this->inShop(fn () => CatalogContent::query()->create([
            'shop_id' => $this->shop->id, 'type' => 'post', 'external_id' => '1', 'title' => 'סיפורי גבורה',
            'body' => 'סיפורים מהשטח.', 'hash' => 'h1',
            'terms' => ['category' => [['id' => 7, 'name' => 'גבורה ותקומה']], 'post_tag' => ['מלחמה', ['id' => 9]]],
        ]));

        app(BuildIndex::class)->handle($this->shop->id);

        $text = (string) $this->inShop(fn () => RetrievalChunk::query()->where('external_id', '1')->value('text'));
        $this->assertStringContainsString('Category: גבורה ותקומה', $text);
        $this->assertStringContainsString('Post_tag: מלחמה', $text);
    }
}
