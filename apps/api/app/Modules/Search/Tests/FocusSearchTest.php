<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * "מקדחה בצבע שחור" on Gueta showed black hooks and handles: "בצבע" is not a word a black drill
 * holds, so the hooks, holding "בצבע שחור", won. "בצבע" now only says a colour follows; and when
 * no product holds every word, the narrower searches that do find are offered to choose from.
 */
final class FocusSearchTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = __DIR__.'/../resources/search/hebrew-search-engine.js';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create();
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);
    }

    public function test_a_colour_after_בצבע_is_a_colour_of_the_drill_not_a_word_of_its_own(): void
    {
        $this->catalog([
            '1' => ['מקדחה רוטטת 850W', 'כחול'],
            '2' => ['מקדחה נטענת Makita', 'שחור'],
            '3' => ['קולב כפול מסולסל בצבע שחור', 'פרזול'],
            '4' => ['ידית אחיזה גדולה בצבע שחור', 'פרזול'],
        ]);

        $result = $this->search('מקדחה בצבע שחור');

        $this->assertSame(['2'], array_column($result['groups']['product'], 'external_id'), 'the black drill, not the black hooks');
        $this->assertNull($result['focus'], 'something holds every word: nothing to choose');
    }

    public function test_a_kind_named_with_a_colour_shows_that_kind_only_or_none(): void
    {
        // Vans: "תיק לבן" showed a white keychain, white sneakers and a white T-shirt: the colour holds across kinds,
        // and the store has no white bag. The kind named ("תיק", opening "תיק גב", "תיק צד") holds: none is shown.
        $this->catalog([
            '1' => ['תיק גב Old Skool', 'שחור'],
            '2' => ['תיק צד Lawler', 'שחור'],
            '3' => ['מחזיק מפתחות Micro Old Skool תיק', 'לבן'],
            '4' => ['נעלי סניקרס Authentic', 'לבן'],
            '5' => ['נעלי סניקרס Era', 'שחור'],
        ]);

        // no white bag: the bags, said to be without the white (as Aviad asked, 2026-10-25: the nearest, not "not found"),
        // never another white thing instead
        $white = $this->search('תיק לבן');
        $bags = array_column($white['groups']['product'], 'external_id');
        sort($bags);
        $this->assertSame(['1', '2'], $bags, 'the bags, not the white keychain nor the white sneakers');
        $this->assertSame(['לבן'], $white['loosened']['left'] ?? null);
        $black = array_column($this->search('תיק שחור')['groups']['product'], 'external_id');
        sort($black);
        $this->assertSame(['1', '2'], $black, 'the black bags, not the black sneakers');
        $this->assertSame(['4'], array_column($this->search('נעליים לבנות')['groups']['product'], 'external_id'), 'the plural names the kind "נעלי" opens');
    }

    public function test_a_kind_holds_what_the_store_files_under_it_whatever_its_title_opens_with(): void
    {
        // Story: "נעלים לבנים" kept only the titles opening with "נעלי", none white; the white sneakers the store tags
        // "נעליים" were left out, and nothing was shown.
        $this->catalog([
            '1' => ['נעלי עקב לנשים Piper', 'נעליים'],
            '2' => ['נעלי בובה לנשים Delia', 'נעליים'],
            '3' => ['סניקרס לנשים Rife לבן', 'נעליים_נשים'],
            '4' => ['חולצה לבנה לגברים', 'ביגוד_גברים'],
        ]);

        $this->assertSame(['3'], array_column($this->search('נעליים לבנות')['groups']['product'], 'external_id'), 'the white sneakers, filed as shoes; never the white shirt');
    }

    public function test_whom_a_product_is_for_is_a_filter_read_from_the_words(): void
    {
        // Story: "חולצה לגברים" showed women's shirts too; the store says whom each is for in its titles and tags.
        $this->catalog([
            '1' => ['חולצה מכופתרת לגברים Mateo', 'ביגוד'],
            '2' => ['חולצה מכופתרת לנשים Kayla', 'ביגוד'],
            '3' => ['חולצה מכופתרת Rains', '_unisex'],
            '4' => ['חולצה לילדים Mini', 'ביגוד_ילדים'],
            '5' => ['מכנסיים לנשים Lou', 'ביגוד'],
        ]);

        $men = array_column($this->search('חולצה לגברים')['groups']['product'], 'external_id');
        sort($men);
        $this->assertSame(['1', '3'], $men, 'the man\'s shirt and the unisex one, never the woman\'s or the child\'s');
        $this->assertSame(['4'], array_column($this->search('חולצה לילדים')['groups']['product'], 'external_id'));
    }

    public function test_nothing_as_asked_shows_the_nearest_looser_search_and_says_what_it_left_out(): void
    {
        // Story: "חולצה לגברים בצבע לבן עם פסים" showed "לא מצאנו" while the AI found striped and white men's shirts: no
        // shirt here is men's, white and striped. In code: first without the colour, then without the other words.
        $this->catalog([
            '1' => ['חולצה מכופתרת לגברים Striped פסים', 'כחול'],
            '2' => ['חולצה מכופתרת לגברים Mateo', 'לבן'],
            '3' => ['חולצה מכופתרת לנשים Kayla פסים', 'לבן'],
            '4' => ['מכנסיים לגברים Lou', 'שחור'],
        ]);

        $found = $this->search('חולצה לגברים בצבע לבן עם פסים');

        $this->assertSame(['1'], array_column($found['groups']['product'], 'external_id'), 'the striped men\'s shirt, whom it is for kept');
        $this->assertSame(['לבן'], $found['loosened']['left'] ?? null, 'and says the white was left out');
        $this->assertSame([['query' => 'חולצה לגברים לבן', 'count' => 1]], $found['loosened']['also'] ?? null, 'the white men\'s shirt a press away');
        $this->assertArrayNotHasKey('loosened', $this->search('חולצה לגברים'), 'found as asked: nothing loosened');
    }

    public function test_a_word_the_store_does_not_write_is_read_as_the_store_word_it_means(): void
    {
        // "חולצה מפוספסת" in a store that writes "פסים": the striped shirts, and the word is not "not found". The model
        // chooses among the store's own words, once per word; a word it names that the store does not say is no answer.
        $asked = [];
        $this->app->instance(ChatModel::class, new class($asked) implements ChatModel
        {
            /** @param list<string> $asked */
            public function __construct(private array &$asked) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $input = json_decode($user, true);
                $this->asked[] = $input['word'];

                return new ModelReply(['term' => match (true) {
                    str_starts_with((string) $input['word'], 'מפוספס') => in_array('פסים', $input['store_words'], true) ? 'פסים' : '',
                    default => 'מילה שאין בחנות',
                }], 400, 5);
            }
        });
        $this->catalog([
            '1' => ['חולצה מכופתרת Striped פסים', 'ביגוד'],
            '2' => ['חולצה ארוכה Breton פסים', 'ביגוד'],
            '3' => ['חולצה מכופתרת Plain', 'ביגוד'],
            '4' => ['מכנסיים Lou', 'ביגוד'],
        ]);

        $found = $this->shopperSearch('חולצה מפוספסת');

        $this->assertContains($found['groups']['product'][0]['external_id'] ?? null, ['1', '2']);
        $this->assertSame([['word' => 'מפוספסת', 'term' => 'פסים']], $found['near'] ?? null, 'read as the store writes it');
        $this->assertNotContains('מפוספסת', (array) $found['unknown']);
        $this->shopperSearch('חולצה מפוספסת');
        $this->assertSame(['מפוספסת'], $asked, 'asked once, kept');

        $this->assertArrayNotHasKey('near', $this->shopperSearch('חולצה בגוון קרמל'), 'a word the store does not say is no answer');

        Features::override('search.word_meanings', false, $this->shop->id);
        $this->assertArrayNotHasKey('near', $this->shopperSearch('חולצה מקושקשת'), 'off for the store: never asked');
        $this->assertNotContains('מקושקשת', $asked);
    }

    public function test_a_latin_name_typed_in_hebrew_finds_it(): void
    {
        // Story: "תיק ליגו" found two bags, none of the Herschel LEGO ones.
        $this->catalog([
            '1' => ['תיק גב Herschel LEGO Classic', 'תיקים'],
            '2' => ['תיק צד Orlanado', 'תיקים'],
            '3' => ['כובע מצחייה Madison Logo Cap', 'כובעים'],
        ]);

        $this->assertSame(['1'], array_column($this->search('תיק ליגו')['groups']['product'], 'external_id'));
        $this->assertSame(['1'], array_column($this->search('הרשל')['groups']['product'], 'external_id'));
        $this->assertNotContains('3', array_column($this->search('ליגו')['groups']['product'], 'external_id'), '"Logo" is "לוגו", not "ליגו"');
    }

    public function test_a_colour_is_one_word_whatever_its_gender(): void
    {
        // Gueta: "מברגה שחורה" showed two handles, holding "שחורה" and "הברגה" (threading), and none
        // of the screwdrivers whose colour is "שחור".
        $this->catalog([
            '1' => ['מברגה נטענת Makita', 'שחור'],
            '2' => ['מברגה רוטטת Einhell', 'כחול'],
            '3' => ['ידית שחורה 152 מ"מ', 'הברגה'],
        ]);

        $found = array_column($this->search('מברגה שחורה')['groups']['product'], 'external_id');

        $this->assertSame('1', $found[0], 'the black screwdriver first');
        $this->assertNotContains('2', $found, 'not the blue one');
        $this->assertSame('1', $this->search('מברגה שחור')['groups']['product'][0]['external_id'], 'and in the masculine too');
    }

    public function test_a_guide_or_a_page_shows_only_when_it_holds_every_word(): void
    {
        $this->catalog(['1' => ['מברגה נטענת Makita', 'שחור']]);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['20' => 'עגלה שחורה לחצר', '21' => 'איך בוחרים מברגה שחורה'] as $id => $title) {
                CatalogContent::query()->create(['shop_id' => $this->shop->id, 'type' => 'page', 'external_id' => (string) $id, 'title' => $title,
                    'url' => "https://www.store.test/?p={$id}", 'terms' => [], 'hash' => "h{$id}"]);
            }
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $content = array_column($this->search('מברגה שחורה')['groups']['content'], 'external_id');

        $this->assertSame(['21'], $content, 'the guide about black screwdrivers, not the black cart');
    }

    public function test_when_nothing_holds_every_word_the_narrower_searches_are_offered(): void
    {
        $this->catalog([
            '1' => ['מקדחה רוטטת 850W', 'כחול'],
            '2' => ['מקדחה נטענת Einhell', 'אדום'],
            '3' => ['קולב כפול מסולסל בצבע שחור', 'פרזול'],
            '4' => ['ידית אחיזה גדולה בצבע שחור', 'פרזול'],
            '5' => ['כפתור עגול לארון בצבע שחור', 'פרזול'],
        ]);

        $result = $this->search('מקדחה בצבע שחור');

        $this->assertSame([['query' => 'מקדחה', 'count' => 2], ['query' => 'בצבע שחור', 'count' => 3]], $result['focus']['options']);
        $this->assertNull($this->search('מקדחה בצבע שחור עד 500')['focus'], 'with a price, the price narrows');
        $this->assertNull($this->search('איזו מקדחה בצבע שחור?')['focus'], 'a question goes to the assistant');
        $this->assertNull($this->search('מקדחה')['focus'], 'one word: nothing to narrow');
    }

    public function test_the_browser_offers_the_same_choices_as_the_server(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        $items = [
            ['id' => 'p:1', 'title' => 'מקדחה רוטטת 850W', 'keywords' => 'כחול'],
            ['id' => 'p:2', 'title' => 'מקדחה נטענת Einhell', 'keywords' => 'אדום'],
            ['id' => 'p:3', 'title' => 'קולב כפול מסולסל בצבע שחור', 'keywords' => 'פרזול'],
            ['id' => 'p:4', 'title' => 'מברגה Makita בגוון ירוק', 'keywords' => 'כלי עבודה'],
            ['id' => 'k:1', 'title' => 'מקדחות', 'keywords' => 'כלי עבודה'],
        ];
        $queries = ['מקדחה בצבע שחור', 'מקדחה שחורה', 'קולב שחור', 'קולב שחורים', 'מברגה בגוון אדום', 'קולב בצבע ירוק', 'מקדחה מקיטה ירוקה', 'קרש'];
        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');const i=e.build('.json_encode($items, JSON_UNESCAPED_UNICODE).');const out={};'
            .'for(const q of '.json_encode($queries, JSON_UNESCAPED_UNICODE).'){out[q]={focus:e.focus(i,q),hits:e.search(i,q).map(h=>h.id+(h.full?"*":""))};}'
            .'process.stdout.write(JSON.stringify(out));';
        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();
        $browser = json_decode($process->getOutput(), true);
        $index = HebrewSearch::build($items);

        foreach ($queries as $query) {
            $this->assertSame(HebrewSearch::focus($index, $query), $browser[$query]['focus'], "\"{$query}\": the choices differ");
            $this->assertSame(array_map(fn (array $h): string => $h['id'].($h['full'] ? '*' : ''), HebrewSearch::search($index, $query)), $browser[$query]['hits'], "\"{$query}\": the hits differ");
        }
        $this->assertNotSame([], $browser['מקדחה בצבע שחור']['focus']);
    }

    /** @param  array<string, array{0: string, 1: string}>  $products  id => [title, a word of its attributes] */
    private function catalog(array $products): void
    {
        app(TenantContext::class)->run($this->shop->id, function () use ($products): void {
            foreach ($products as $id => [$title, $tag]) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => [$tag]], 'price' => 100,
                ]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    /** @return array<string, mixed> */
    private function search(string $query): array
    {
        return app(SearchCatalog::class)->handle($this->shop->id, $query, count: false);
    }

    /** @return array<string, mixed> a search as a shopper's own, from the storefront (words read by meaning) */
    private function shopperSearch(string $query): array
    {
        return app(SearchCatalog::class)->handle($this->shop->id, $query, count: false, readWords: true);
    }
}
