<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Filament\Merchant\Pages\AboutStore;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Question;
use App\Modules\Assistant\Support\RepeatedText;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What the store says about itself: what every product page repeats, what the team wrote, and the
 * team's answers. Read by code, kept per shop, and read by the assistant before any page.
 */
final class ShopKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    public function test_what_most_product_pages_repeat_is_the_stores_and_the_rest_is_not(): void
    {
        $pages = array_map(fn (int $n): array => RepeatedText::sentences($this->page($n)), [1, 2, 3, 4]);
        $found = array_column(RepeatedText::repeated($pages, 0.6), 'text');

        $this->assertContains('אפשרות לשריין מוצר באמצעות פריסת תשלומים וללא תפיסת מסגרת אשראי.', $found);
        $this->assertContains('החזר כספי מלא תוך 14 יום', $found);
        $this->assertContains('אחריות 12 חודשים על כל תכשיט', $found);
        $this->assertNotContains('שלמו היום רק 101 ₪', $found, 'the price differs from product to product');
        $this->assertNotContains('טבעת זהב מספר 1 עם יהלום', $found, 'a product\'s own words');
        $this->assertSame([], array_filter($found, fn (string $s): bool => str_starts_with($s, 'עבור לפריט')), 'a gallery\'s numbered labels');
        $this->assertSame([], array_filter($found, fn (string $s): bool => str_contains($s, 'תפריט')), 'menus are not read');
    }

    public function test_the_shop_keeps_what_its_pages_repeat_its_own_words_and_its_answers(): void
    {
        $this->shop = Shop::factory()->create();
        Http::fake(fn ($request) => Http::response($this->page((int) preg_replace('/\D/', '', (string) $request->url()))));

        $this->inShop(function (): void {
            foreach ([1, 2, 3, 4, 5] as $n) {
                CatalogProduct::query()->create([
                    'external_id' => (string) $n, 'type' => 'simple', 'status' => 'publish', 'title' => "טבעת {$n}",
                    'url' => "https://rings.test/products/ring-{$n}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$n}", 'payload' => [],
                ]);
            }
            // A product linking to another address is never read.
            CatalogProduct::query()->create([
                'external_id' => '9', 'type' => 'simple', 'status' => 'publish', 'title' => 'אחר',
                'url' => 'http://169.254.169.254/latest', 'in_stock' => true, 'purchasable' => true, 'hash' => 'h9', 'payload' => [],
            ]);

            $read = app(BuildShopKnowledge::class)->repeated($this->shop->id);
            $this->assertSame(5, $read['pages']);
        });
        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '169.254'));

        $entries = $this->inShop(fn (): array => app(BuildShopKnowledge::class)->entries());
        $repeated = $entries[BuildShopKnowledge::REPEATED];
        $this->assertSame(CatalogContent::KNOWLEDGE, $repeated->type);
        $this->assertStringContainsString('החזר כספי מלא תוך 14 יום', $repeated->body);
        $this->assertSame('מידע שמופיע בכל דפי המוצר באתר', $repeated->title);

        // The team removes a sentence: gone now, and at the next reading too.
        $this->inShop(fn () => app(BuildShopKnowledge::class)->hide('החזר כספי מלא תוך 14 יום'));
        $this->inShop(fn () => app(BuildShopKnowledge::class)->repeated($this->shop->id));
        $this->assertStringNotContainsString('החזר כספי', $this->inShop(fn () => app(BuildShopKnowledge::class)->entries())[BuildShopKnowledge::REPEATED]->body);

        // A team answer joins at once.
        $this->inShop(fn () => AssistantAnswer::query()->create([
            'question_key' => Question::key('אפשר לאסוף מהחנות?'), 'question' => 'אפשר לאסוף מהחנות?',
            'answer' => 'כן, איסוף עצמי מהחנות בתל אביב בלי תשלום.', 'outcome' => AssistantAnswer::ANSWERED,
            'source' => 'team', 'status' => AssistantAnswer::SHOWN, 'prompt_version' => 0, 'last_asked_at' => now(),
        ]));
        $team = $this->inShop(fn () => app(BuildShopKnowledge::class)->entries())[BuildShopKnowledge::TEAM];
        $this->assertStringContainsString("שאלה: אפשר לאסוף מהחנות?\nתשובה: כן, איסוף עצמי", $team->body);

        // The assistant reads all of it first: the matching team answer, the repeated text and the team's own words.
        $this->inShop(fn () => app(BuildShopKnowledge::class)->own('אפשר לשריין תכשיט במקדמה של 10%.'));
        $passages = $this->inShop(fn (): array => (new ReflectionMethod(AnswerSiteQuestion::class, 'knowledge'))
            ->invoke(app(AnswerSiteQuestion::class), 'איך עושים איסוף מהחנות?', $this->shop->id, [['source' => 'content', 'source_id' => 'x', 'title' => 'דף', 'text' => 'ט', 'similarity' => 0.5]]));

        $this->assertCount(4, $passages);
        $this->assertSame(['store_team', 'team_answers', 'every_product_page'], array_column(array_slice($passages, 0, 3), 'from'), 'the team\'s own words first, and the writer is told who wrote each');
        $this->assertSame('דף', $passages[3]['title'], 'the pages the meaning search found come after');
        $this->assertArrayNotHasKey('from', $passages[3]);
        $this->assertStringContainsString('איסוף עצמי', implode(' ', array_column($passages, 'text')));
        // An answer from what the store says about itself is marked so: the drawer shows no products beside it.
        $sources = $this->inShop(fn (): array => (new ReflectionMethod(AnswerSiteQuestion::class, 'sources'))->invoke(app(AnswerSiteQuestion::class), [$passages[0], $passages[3]]));
        $this->assertTrue($sources[0]['store'] ?? false);
        $this->assertArrayNotHasKey('store', $sources[1], 'a page the meaning search found is no word of the store about itself');

        // Not in another shop, and never a search result of its own.
        $other = Shop::factory()->create();
        $this->assertSame([], app(TenantContext::class)->run($other->id, fn (): array => app(BuildShopKnowledge::class)->entries()));
    }

    public function test_the_writer_is_told_the_team_word_comes_first_and_the_checker_reads_its_last_version(): void
    {
        $prompts = __DIR__.'/../Prompts/';

        $this->assertSame(file_get_contents($prompts.'answer_site.v11.md'), AnswerSiteQuestion::prompt('answer_site'), 'v11: the store speaks as itself ("יש לנו"); the products\' prices, quoted only as given');
        $this->assertStringContainsString('יש לנו', AnswerSiteQuestion::prompt('answer_site'));
        $this->assertStringContainsString('"store_team"', AnswerSiteQuestion::prompt('answer_site'));
        $this->assertSame(file_get_contents($prompts.'verify_site.v9.md'), AnswerSiteQuestion::prompt('verify_site'), 'the checker reads its last version: approve, revise or reject; a product\'s title is what it is about, guidance is no claim; an honest close alternative fits; a price only as given');
        $this->assertSame(file_get_contents($prompts.'scope_site.v6.md'), AnswerSiteQuestion::prompt('scope_site'), 'the scope check also writes the question again without its typing mistakes');
    }

    public function test_the_merchant_sees_and_edits_what_the_assistant_knows(): void
    {
        $this->shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        Queue::fake();
        $this->inShop(fn () => CatalogContent::query()->create(['type' => 'page', 'external_id' => 'policy-1', 'title' => 'מדיניות החזרות', 'url' => 'https://rings.test/policies/refund', 'body' => 'החזרה תוך 14 יום.', 'hash' => 'p1']));

        Livewire::test(AboutStore::class)
            ->assertOk()
            ->assertSee('מדיניות החזרות')
            ->set('newInfo', 'משלוח עד הבית תוך 3 ימי עסקים.')
            ->call('addInfo');

        $this->assertSame('- משלוח עד הבית תוך 3 ימי עסקים.', $this->inShop(fn () => app(BuildShopKnowledge::class)->entries())[BuildShopKnowledge::OWN]->body);
        Livewire::test(AboutStore::class)->assertSet('own', 'משלוח עד הבית תוך 3 ימי עסקים.')->assertSee('משלוח עד הבית תוך 3 ימי עסקים.');
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    /** A product page of a theme: a menu, the product's own words, the store's repeated lines. */
    private function page(int $n): string
    {
        return <<<HTML
            <html><body><nav><a>תפריט ראשי של החנות</a></nav>
            <main>
              <h1>טבעת זהב מספר {$n} עם יהלום</h1>
              <button aria-label="x"><span>עבור לפריט {$n}</span></button>
              <div class="pps"><button><span>אפשרות לשריין מוצר באמצעות פריסת תשלומים וללא תפיסת מסגרת אשראי.</span>
              <span>שלמו היום רק 10{$n} ₪</span></button></div>
              <ul><li>החזר כספי מלא תוך 14 יום</li><li>אחריות 12 חודשים על כל תכשיט</li></ul>
              <video><source src="a.mp4">Your browser does not support the video tag.</video>
            </main>
            <footer>כל הזכויות שמורות לחנות</footer></body></html>
            HTML;
    }
}
