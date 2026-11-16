<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchWordMeaning;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A search in another language than the store's finds what the store calls it. Jean Rachel QA, 16.11: in an English store
 * "טבעת" showed 7 (the rings whose picture tags were read in Hebrew), "ring" 65 and "rings" 16 (earrings among them).
 * Rocks, a Hebrew store: "earrings" found the six whose text says it in English, of hundreds of "עגילים". The store's word
 * is read by the small model among the store's own words (WordMeanings, faked here as a dictionary); nothing is listed in
 * code.
 */
final class CrossLanguageSearchTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> the words the model was asked */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $asked = &$this->asked;
        $this->app->instance(ChatModel::class, new class($asked) implements ChatModel
        {
            private const SAYS = ['טבעת' => 'ring', 'זהב' => 'gold', 'צמיד' => 'bracelet', 'earrings' => 'עגילים', 'ring' => 'טבעת', 'shoes' => 'נעליים'];

            private const SHY = ['צמידון' => 'bracelet', 'towels' => 'מגבת', 'כובע' => 'hat'];

            /** @param list<string> $asked */
            public function __construct(private array &$asked) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $input = json_decode($user, true);
                $this->asked[] = $input['word'];
                // a Hebrew store asked in Hebrew finds no English word for "צמיד": none
                // translated right, and called no match since the store writes another form of it (Jean, Vans, Take Me Home, 16.11)
                if (isset(self::SHY[$input['word']])) {
                    return new ModelReply(['translation' => self::SHY[$input['word']], 'term' => '', 'same' => false], 300, 5);
                }
                $term = self::SAYS[$input['word']] ?? '';
                $term = $input['language'] === 'he' && preg_match('/^[a-z]/', $term) === 1 ? '' : $term;
                $held = array_map(fn (string $w): string => mb_strtolower($w), $input['store_words']);

                return new ModelReply(['translation' => $term, 'term' => in_array($term, $held, true) ? $term : '', 'same' => $term !== ''], 300, 5);
            }
        });
    }

    public function test_a_hebrew_word_in_an_english_store_finds_every_product_the_store_calls_it(): void
    {
        $shop = $this->store('en', [
            '1' => ['14K Gold Wrap Ring', 'טבעת זהב'], // its picture tags read in Hebrew
            '2' => ['Silver Lace Ring', 'טבעת כסף'],
            '3' => ['Gold Leaf Ring', 'rings'],
            '4' => ['Silver Band Ring', 'rings'],
            '5' => ['Gold Hoop Earrings', 'earrings'],
            '6' => ['Silver Stud Earrings', 'earrings'],
            '7' => ['Gold Heart Necklace', 'necklaces'],
            '8' => ['Silver Chain Necklace', 'necklaces'],
            '9' => ['Gold Tennis Bracelet', 'bracelets'],
            '10' => ['Silver Cuff Bracelet', 'bracelets'],
        ]);

        $ring = $this->found($shop, 'ring');
        $this->assertEqualsCanonicalizing(['1', '2', '3', '4'], $ring, 'every ring');
        $this->assertEqualsCanonicalizing($ring, $this->found($shop, 'טבעת'), 'in Hebrew, every ring: not the two tagged in Hebrew');
        $this->assertSame([['word' => 'טבעת', 'term' => 'ring']], $this->search($shop, 'טבעת')['near'] ?? null, 'and says how it was read');
        $this->assertEqualsCanonicalizing(['1', '3'], $this->found($shop, 'טבעת זהב'), 'every word counts: the gold rings, not every ring and every gold piece');
        $this->assertEqualsCanonicalizing(['9', '10'], $this->found($shop, 'צמיד'), 'a word no product says, read the same way');

        $this->search($shop, 'טבעת');
        $this->assertSame(1, count(array_keys($this->asked, 'טבעת', true)), 'asked once, kept');
    }

    public function test_an_english_word_in_a_hebrew_store_finds_what_the_store_calls_it(): void
    {
        $shop = $this->store('he', [
            '1' => ['עגילי סוליטר זהב earrings', 'עגילים'],
            '2' => ['עגילי חישוק כסף', 'עגילים'],
            '3' => ['עגילים צמודים יהלום', 'עגילים'],
            '4' => ['טבעת סוליטר זהב', 'טבעות'],
            '5' => ['טבעת נישואין כסף', 'טבעות'],
            '6' => ['שרשרת לב זהב', 'שרשראות'],
            '7' => ['שרשרת חוליות כסף', 'שרשראות'],
            '8' => ['צמיד טניס זהב', 'צמידים'],
        ]);

        $this->assertEqualsCanonicalizing(['1', '2', '3'], $this->found($shop, 'earrings'), 'every pair, not only the one saying "earrings"');
        $this->assertEqualsCanonicalizing(['4', '5'], $this->found($shop, 'טבעת'), 'a Hebrew word in a Hebrew store as it was');
        $this->assertNotContains('טבעת', $this->asked, 'and never asked about');
    }

    public function test_the_word_most_of_the_store_says_is_a_word_to_read_as(): void
    {
        // Vans, 16.11: "shoes" found nothing, its "נעליים" on 156 of 300 products left out of the words to choose from
        $shop = $this->store('he', [
            '1' => ['נעליים Old Skool שחור', 'נעליים'], '2' => ['נעליים Authentic אדום', 'נעליים'], '3' => ['נעליים Era כחול', 'נעליים'],
            '4' => ['נעליים Slip-On לבן', 'נעליים'], '5' => ['גרביים קרו לבן', 'גרביים'], '6' => ['גרביים קרו שחור', 'גרביים'], '7' => ['קפוצון לוגו', 'קפוצונים'],
        ]);

        $this->assertEqualsCanonicalizing(['1', '2', '3', '4'], $this->found($shop, 'shoes'));
    }

    public function test_what_the_word_plainly_means_is_taken_when_the_store_writes_it_in_another_form(): void
    {
        $en = $this->store('en', [
            '1' => ['Silver Chain Bracelets', 'x'], '2' => ['Gold Cuff Bracelets', 'x'], '3' => ['Gold Ring', 'x'], '4' => ['Silver Ring', 'x'],
            '5' => ['Gold Necklace', 'x'], '6' => ['Silver Necklace', 'x'], '7' => ['Unisex Chain', 'unisex'], '8' => ['Unisex Band', 'unisex'],
        ]);
        $this->assertEqualsCanonicalizing(['1', '2'], $this->found($en, 'צמידון'), '"bracelet", and the store writes "bracelets"');
        $this->assertArrayNotHasKey('near', $this->search($en, 'כובע'), '"hat", which the store never writes: none');

        $he = $this->store('he', [
            '1' => ['מגבות רחצה ארנב', 'מגבות'], '2' => ['מגבות חוף פסים', 'מגבות'], '3' => ['שמיכה חורף', 'שמיכות'], '4' => ['שמיכה קיץ', 'שמיכות'], '5' => ['בקבוק', 'x'],
        ]);
        $this->assertEqualsCanonicalizing(['1', '2'], $this->found($he, 'towels'), '"מגבת", and the store writes "מגבות"');
    }

    public function test_a_none_read_before_the_forms_gate_is_read_again_once(): void
    {
        $shop = $this->store('en', ['1' => ['Silver Chain Bracelets', 'x'], '2' => ['Gold Cuff Bracelets', 'x'], '3' => ['Gold Ring', 'x'], '4' => ['Silver Ring', 'x'], '5' => ['Gold Necklace', 'x']]);
        $this->found($shop, 'צמידון');
        app(TenantContext::class)->run($shop->id, fn () => SearchWordMeaning::query()->update(['term' => null, 'model' => 'gpt-5.4-nano@word_meaning.v2']));
        $this->asked = [];

        $this->assertEqualsCanonicalizing(['1', '2'], $this->found($shop, 'צמידון'), 'read again with the gate');
        $this->found($shop, 'צמידון');
        $this->assertSame(['צמידון'], $this->asked, 'once');
    }

    public function test_while_typing_nothing_is_asked(): void
    {
        $shop = $this->store('en', ['1' => ['Gold Ring', 'rings'], '2' => ['Silver Ring', 'rings'], '3' => ['Gold Necklace', 'necklaces'], '4' => ['Silver Necklace', 'necklaces'], '5' => ['Silver Bracelet', 'x']]);

        app(SearchCatalog::class)->handle($shop->id, 'טבעת', count: false, meaning: false, readWords: true);
        app(SearchCatalog::class)->handle($shop->id, 'טבעת', count: false);

        $this->assertSame([], $this->asked, 'spelling only while typing, and never for the store\'s own checks');
    }

    public function test_a_store_whose_language_changed_reads_its_none_again(): void
    {
        // built in Hebrew, then English (the two demos of 15.11): "צמיד" read in Hebrew found no English word and kept "none"
        $shop = $this->store('he', [
            '1' => ['Gold Tennis Bracelet', 'bracelets'], '2' => ['Silver Cuff Bracelet', 'bracelets'],
            '3' => ['Gold Ring', 'rings'], '4' => ['Silver Ring', 'rings'], '5' => ['Gold Necklace', 'necklaces'],
        ]);
        $this->assertSame([], $this->found($shop, 'צמיד'));

        $shop->forceFill(['content_locale' => 'en'])->save();

        $this->assertEqualsCanonicalizing(['1', '2'], $this->found($shop, 'צמיד'), 'read again in English: the bracelets');
    }

    public function test_an_english_plural_is_its_singular(): void
    {
        $shop = $this->store('en', [
            '1' => ['14K Gold Wrap Ring', 'x'], '2' => ['Mixed Metal Lace Ring', 'x'], '3' => ['Stacking Rings Set', 'x'],
            '4' => ['CZ Hoop Earrings', 'x'], '5' => ['Wire Wrap Earrings', 'x'], '6' => ['Gold Heart Necklace', 'x'],
            '7' => ['Layered Necklaces', 'x'], '8' => ['Jewelry Boxes', 'x'], '9' => ['Gift Box', 'x'], '10' => ['Baby Bracelet', 'x'], '11' => ['Babies Gift Set', 'x'],
        ]);

        $this->assertEqualsCanonicalizing(['1', '2', '3'], $this->found($shop, 'rings'), 'every ring, never the earrings');
        $this->assertEqualsCanonicalizing($this->found($shop, 'ring'), $this->found($shop, 'rings'));
        $this->assertEqualsCanonicalizing(['6', '7'], $this->found($shop, 'necklaces'));
        $this->assertEqualsCanonicalizing(['8', '9'], $this->found($shop, 'boxes'));
        $this->assertEqualsCanonicalizing(['10', '11'], $this->found($shop, 'babies'));
        $this->assertNotContains('1', $this->found($shop, 'earrings'));

        $this->assertSame('ring', HebrewSearch::singular('rings'));
        $this->assertSame('dress', HebrewSearch::singular('dresses'));
        $this->assertSame('dress', HebrewSearch::singular('dress'));
        $this->assertSame('baby', HebrewSearch::singular('babies'));
        $this->assertSame('box', HebrewSearch::singular('boxes'));
        $this->assertSame('shoe', HebrewSearch::singular('shoes'));
        $this->assertSame('glass', HebrewSearch::singular('glass'));
    }

    public function test_the_drawer_reads_a_plural_as_the_server_does(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node is not installed here; CI runs this with Node.');
        }
        $items = [
            ['id' => 'p:1', 't' => 'product', 'title' => '14K Gold Wrap Ring'], ['id' => 'p:2', 't' => 'product', 'title' => 'Stacking Rings Set'],
            ['id' => 'p:3', 't' => 'product', 'title' => 'CZ Hoop Earrings'], ['id' => 'p:4', 't' => 'product', 'title' => 'Jewelry Boxes'], ['id' => 'p:5', 't' => 'product', 'title' => 'Gift Box'],
        ];
        $queries = ['rings', 'ring', 'boxes', 'earrings'];
        $words = ['rings', 'dresses', 'dress', 'babies', 'boxes', 'shoes', 'glass', 'bus'];
        $harness = 'const e=require('.json_encode(realpath(__DIR__.'/../resources/search/hebrew-search-engine.js')).');const i=e.build('.json_encode($items).');const out={hits:{},one:{}};'
            .'for(const q of '.json_encode($queries).'){out.hits[q]=e.search(i,q).map(h=>h.id).sort();}'
            .'for(const w of '.json_encode($words).'){out.one[w]=e.singular(w);}process.stdout.write(JSON.stringify(out));';
        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();
        $browser = json_decode($process->getOutput(), true);
        $index = HebrewSearch::build($items);

        foreach ($queries as $q) {
            $server = array_column(HebrewSearch::search($index, $q), 'id');
            sort($server);
            $this->assertSame($server, $browser['hits'][$q], "\"{$q}\" finds the same in the drawer");
        }
        $this->assertSame(['p:1', 'p:2'], $browser['hits']['rings'], 'every ring, never the earrings');
        foreach ($words as $w) {
            $this->assertSame(HebrewSearch::singular($w), $browser['one'][$w]);
        }
    }

    /** @param  array<string, array{0: string, 1: string}>  $products  id => title, tag */
    private function store(string $locale, array $products): Shop
    {
        $shop = Shop::factory()->create(['content_locale' => $locale]);
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        app(TenantContext::class)->run($shop->id, function () use ($shop, $products): void {
            foreach ($products as $id => [$title, $tag]) {
                CatalogProduct::query()->create([
                    'shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => [$tag]], 'price' => 100,
                ]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);

        return $shop;
    }

    /** @return array<string, mixed> a shopper's own search, at a pause or on Enter */
    private function search(Shop $shop, string $query): array
    {
        return app(SearchCatalog::class)->handle($shop->id, $query, count: false, readWords: true);
    }

    /** @return list<string> */
    private function found(Shop $shop, string $query): array
    {
        return array_column($this->search($shop, $query)['groups']['product'], 'external_id');
    }
}
