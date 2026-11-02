<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Actions\WriteWordFamilies;
use App\Modules\Search\Models\SearchWordFamily;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\StoreWords;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sela: "שבי" should find the books on hostages; Vans: "משובצות" the checkered ones whatever form they say. One family of
 * models writes a store's word families, the other checks, code keeps only the store's own words.
 */
final class WordFamiliesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{provider: string, input: array<string, mixed>}> */
    public array $calls = [];

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create();
        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly WordFamiliesTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider->value, 'input' => json_decode($user, true)];

                return $provider === AiProviderName::OpenAi
                    // the writer: a family of hostages, one of checks, a word the store does not say, a word twice, and a wrong one
                    ? new ModelReply(['families' => [['שבי', 'חטופים', 'חטוף', 'אסירים'], ['משובצות', 'משבצות', 'חטופים'], ['ספר', 'מחברת']]], 900, 200)
                    // the checker, a family at a time: the notebook is no book
                    : new ModelReply(['families' => [
                        ['id' => 'f1', 'kind' => 'same_thing', 'keep' => ['שבי', 'חטופים', 'חטוף']],
                        ['id' => 'f2', 'kind' => 'forms', 'keep' => ['משובצות', 'משבצות']],
                        ['id' => 'f3', 'kind' => 'wrong', 'keep' => ['ספר', 'מחברת']],
                    ]], 300, 20);
            }
        });
    }

    public function test_a_stores_families_are_written_checked_kept_to_its_words_and_found_by_each_other(): void
    {
        $this->catalog([
            '1' => ['מפגש עם עצמי בגיהינום', 'ספר חטופים זיכרון'],
            '2' => ['ימים של שבי', 'ספר שבי זיכרון'],
            '3' => ['חטוף - אלי שרעבי', 'ספר חטוף עדות'],
            '4' => ['חולצה משובצת Axe', 'משובצות כותנה'],
            '5' => ['מכנסיים במשבצות', 'משבצות כותנה'],
            '6' => ['מחברת ספירלה', 'מחברת נייר'],
            '7' => ['מחברת משורטטת', 'מחברת נייר'],
            '8' => ['כובע צמר', 'צמר עדות'],
            '9' => ['צעיף צמר', 'צמר זיכרון'],
            '10' => ['ספר שירה', 'ספר שבי שירה'],
            '11' => ['חולצה משובצת Mini', 'משובצות שירה'],
            '12' => ['חטופים בעזה', 'חטופים חטוף עדות'],
            '13' => ['שמיכה במשבצות', 'משבצות צמר'],
        ]);

        $run = app(WriteWordFamilies::class)->handle($this->shop->id);

        $this->assertNotNull($run);
        $this->assertSame(['openai', 'anthropic'], array_column($this->calls, 'provider'), 'one family writes, the other checks');
        $families = app(TenantContext::class)->run($this->shop->id, fn (): array => SearchWordFamily::query()->sole()->families);
        $this->assertSame([['שבי', 'חטופים', 'חטוף'], ['משובצות', 'משבצות']], $families, 'only the store\'s words, each once; the checked-out family gone');
        $this->assertNull(app(WriteWordFamilies::class)->handle($this->shop->id), 'the store\'s words unchanged: not written again');

        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();
        $hostages = array_column(app(SearchCatalog::class)->handle($this->shop->id, 'שבי', count: false)['groups']['product'], 'external_id');
        $this->assertContains('1', $hostages, 'a book that says "חטופים" found by "שבי"');
        $this->assertContains('3', $hostages);
        $checks = array_column(app(SearchCatalog::class)->handle($this->shop->id, 'משבצות', count: false)['groups']['product'], 'external_id');
        $this->assertContains('4', $checks, 'the shirt that says "משובצות" found by "משבצות"');
    }

    public function test_words_of_one_root_are_handed_together_with_a_letter_a_form_begins_with(): void
    {
        // Vans, 26.10: "נמר" and "מנומרות" found different shoes
        $groups = array_map(fn (array $g): array => (sort($g) ? $g : $g), WriteWordFamilies::alike(['נמר', 'מנומרות', 'מנומר', 'משבצות', 'משובץ', 'סניקרס']));

        $this->assertContains(['מנומר', 'מנומרות', 'נמר'], $groups);
        $this->assertContains(['משבצות', 'משובץ'], $groups);
        $this->assertNotContains(['מנומר', 'מנומרות'], $groups, 'a group another holds all of adds nothing');
    }

    public function test_a_big_group_is_handed_and_many_groups_are_written_over_several_calls(): void
    {
        // Rocks, 2.11: ten words of one root never reached the writer, and "ריבוע" never met "מרובע"
        $square = ['מרובעות', 'מרובעת', 'מרובע', 'מרובעים', 'מרבעת', 'רבע', 'ריבוע', 'ריבועי', 'ריבועית', 'ריבועים'];
        $this->assertContains($square, WriteWordFamilies::alike($square));

        $letters = ['א', 'ב', 'ג', 'ד', 'ז', 'ח', 'ט', 'כ', 'ל', 'נ', 'ס', 'ע', 'פ', 'צ', 'ק', 'ר', 'ש', 'ת'];
        $products = [];
        foreach ($letters as $a) {
            foreach ($letters as $b) {
                $one = "{$a}{$b}גד";
                $products["{$a}{$b}1"] = ["מוצר {$one}", "{$one} {$one}ים"];
                $products["{$a}{$b}2"] = ["פריט {$one}ים", "{$one} {$one}ים"];
            }
        }
        $this->catalog($products);
        app(WriteWordFamilies::class)->handle($this->shop->id);

        $written = array_values(array_filter($this->calls, fn (array $c): bool => $c['provider'] === 'openai'));
        $this->assertGreaterThan(1, count($written), 'more groups than one call holds: several calls');
        $this->assertLessThanOrEqual(120, max(array_map(fn (array $c): int => count($c['input']['alike']), $written)));
        $this->assertGreaterThan(200, array_sum(array_map(fn (array $c): int => count($c['input']['alike']), $written)), 'past the old cut of 200');
        $this->assertLessThan(count($written[0]['input']['store_words']), count(end($written)['input']['store_words']), 'the next calls read their groups alone');
    }

    public function test_a_short_words_construct_form_is_handed_beside_it(): void
    {
        // Shilav, 2.11: "עגלה" and "עגלת" never met
        $this->assertContains(['עגלה', 'עגלת'], WriteWordFamilies::alike(['עגלה', 'עגלת', 'כובע']));
    }

    public function test_the_same_words_said_by_more_products_write_nothing(): void
    {
        $this->catalog(['1' => ['חולצה משובצת', 'משובצת כותנה'], '2' => ['חולצה משובצת', 'משובצת כותנה'], '3' => ['מכנס במשבצות', 'משבצות כותנה'], '4' => ['מכנס במשבצות', 'משבצות צמר'],
            '5' => ['כובע צמר', 'צמר שירה'], '6' => ['צעיף צמר', 'שירה זיכרון'], '7' => ['ספר שירה', 'זיכרון עדות'], '8' => ['ספר עדות', 'עדות זיכרון'], '9' => ['שמיכה נייר', 'נייר ספירלה'], '10' => ['מחברת נייר', 'נייר ספירלה']]);
        $this->assertNotNull(app(WriteWordFamilies::class)->handle($this->shop->id));

        app(TenantContext::class)->run($this->shop->id, function (): void {
            CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '11', 'type' => 'simple', 'status' => 'publish', 'title' => 'ספר שירה', 'url' => 'https://www.store.test/p/11',
                'in_stock' => true, 'purchasable' => true, 'hash' => 'h11', 'payload' => ['tags' => ['צמר', 'נייר']], 'price' => 100]);
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $this->assertNull(app(WriteWordFamilies::class)->handle($this->shop->id), 'another count, the same words');
    }

    public function test_a_form_past_the_lists_cap_is_read_the_most_said_first(): void
    {
        $record = fn (string $title): array => ['t' => 'product', 'title' => $title, 'kw' => ''];
        $index = ['records' => [$record('מברגה'), $record('מברגות אחת'), $record('מברגות שתיים')]];

        $this->assertSame(['מברגות', 'מברגה'], StoreWords::rareForms($index, ['מברג']), 'two products say it: past the cap, still a form of the store\'s word');
    }

    public function test_a_form_one_product_alone_says_is_read_beside_the_stores_word_of_its_root(): void
    {
        // Vans, 26.10: "נמר" on one sneaker, "מנומר" on three: never a family, "נמר" found one, "מנומרות" four
        $record = fn (string $title, string $kw): array => ['t' => 'product', 'title' => $title, 'kw' => $kw];
        $index = ['records' => ['p:1' => $record('נעלי סניקרס LX', 'נמר חום'), 'p:2' => $record('נעלי סניקרס Authentic', 'מנומר'), 'p:3' => $record('תיק גב', 'מנומר'),
            'p:4' => $record('מכנס ג\'ינס', 'מנומרת כחול'), 'p:5' => $record('כובע', 'פסים מרופט')]];

        $this->assertSame(['מנומרת', 'נמר'], StoreWords::rareForms($index, ['מנומר', 'חום', 'פסים']), 'forms of the store\'s word; "מרופט", alone and of no root of the store\'s, is not');
    }

    public function test_a_stores_words_read_its_tags_as_words_not_as_their_weights(): void
    {
        // a record's tags as the index writes them: [its words, how central] (Sela's "חטופים ושבי")
        $book = fn (string $title): array => ['t' => 'product', 'title' => $title, 'kw' => '', 'tg' => [['חטופים ושבי', 1], ['שבי חמאס', 0.9]]];
        $words = StoreWords::vocabulary([$book('חטוף'), $book('מפגש עם עצמי בגיהינום'), ['t' => 'product', 'title' => 'כלכלה', 'kw' => '', 'tg' => null], ['t' => 'product', 'title' => 'אחר', 'kw' => '', 'tg' => null], ['t' => 'product', 'title' => 'עוד', 'kw' => '', 'tg' => null]], [], 100);

        $this->assertContains('חטופים', $words);
        $this->assertContains('שבי', $words);
        $this->assertContains('חטופים ושבי', $words, 'a tag of two words, whole');
        $this->assertNotContains('Array', $words);
    }

    /** @param array<string, array{0: string, 1: string}> $products */
    private function catalog(array $products): void
    {
        app(TenantContext::class)->run($this->shop->id, function () use ($products): void {
            foreach ($products as $id => [$title, $tags]) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => explode(' ', $tags)], 'price' => 100,
                ]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }
}
