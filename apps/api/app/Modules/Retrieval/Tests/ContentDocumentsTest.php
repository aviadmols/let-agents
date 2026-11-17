<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Retrieval\Sources\ContentDocuments;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a page or a post is found by: its own words once, never its excerpt again before its body, never the state a
 * reading of the store keeps for itself (16.11, the chunk report: Rocks' posts held their opening twice, the digest its
 * "Read: …" lines).
 */
final class ContentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_excerpt_made_of_the_bodys_opening_is_not_said_twice(): void
    {
        $body = '<p>טבעת סוליטר היא טבעת עם יהלום אחד במרכז, והיא הבחירה הקלאסית להצעת נישואין. במדריך הזה נסביר איך בוחרים אחת.</p>';
        $this->assertTrue(ContentDocuments::repeatsBody('טבעת סוליטר היא טבעת עם יהלום אחד במרכז, והיא הבחי…', $body), 'cut in a word');
        $this->assertTrue(ContentDocuments::repeatsBody('טבעת סוליטר היא טבעת עם יהלום אחד במרכז [&hellip;]', $body));
        $this->assertTrue(ContentDocuments::repeatsBody('', $body));
        $this->assertFalse(ContentDocuments::repeatsBody('Everything about choosing an engagement ring, in five steps.', $body), 'an excerpt of its own stays');
    }

    public function test_a_takeaway_that_is_a_sentence_of_the_body_is_seen_as_one(): void
    {
        $body = '<p>3. החזר כספי בגין ביטול עסקה יינתן לכרטיס האשראי בו בוצעה ההזמנה.</p><p>4. שובר מתנה מזכה בקרדיט.</p>';
        $this->assertTrue(ContentDocuments::inBody('Highlight: החזר כספי בגין ביטול עסקה יינתן לכרטיס האשראי בו בוצעה ההזמנה.', $body));
        $this->assertFalse(ContentDocuments::inBody('Highlight: החזר כספי ניתן תוך 14 ימים לכרטיס האשראי.', $body), 'a takeaway in words of its own stays');
        $this->assertFalse(ContentDocuments::inBody('Topic: שובר', $body), 'too short to tell');
    }

    public function test_a_document_holds_its_categories_but_never_a_readings_own_state(): void
    {
        $shop = Shop::factory()->create();
        $docs = app(TenantContext::class)->run($shop->id, function (): array {
            CatalogContent::query()->create(['type' => CatalogContent::POST, 'external_id' => 'p1', 'title' => 'איך בוחרים סוליטר',
                'excerpt' => 'טבעת סוליטר היא טבעת עם יהלום אחד במרכז, והיא הבחי…', 'body' => '<p>טבעת סוליטר היא טבעת עם יהלום אחד במרכז, והיא הבחירה הקלאסית.</p>',
                'terms' => ['categories' => ['מדריכים']], 'hash' => 'a']);
            CatalogContent::query()->create(['type' => CatalogContent::KNOWLEDGE, 'external_id' => CatalogContent::OWN_PREFIX.'knowledge-posts', 'title' => 'מה שהפוסטים אומרים',
                'body' => '- משלוח חינם מעל 300 ש"ח.', 'terms' => ['read' => [['title' => 'מדיניות משלוחים']], 'read_at' => '2026-11-16'], 'hash' => 'b']);

            return collect(iterator_to_array((new ContentDocuments)->documents('x'), false))->keyBy(fn ($d): string => (string) $d->externalId)->all();
        });

        $post = (string) $docs['p1']->text;
        $this->assertStringContainsString('Categories: מדריכים', $post);
        $this->assertSame(1, substr_count($post, 'טבעת סוליטר היא טבעת'), 'the opening once');
        $digest = (string) $docs[CatalogContent::OWN_PREFIX.'knowledge-posts']->text;
        $this->assertStringNotContainsString('Read', $digest);
        $this->assertStringContainsString('משלוח חינם מעל 300', $digest);
    }
}
