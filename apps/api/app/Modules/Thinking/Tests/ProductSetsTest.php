<?php

namespace App\Modules\Thinking\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Thinking\Actions\FindProductSet;
use App\Modules\Thinking\Models\ProductSet;
use App\Modules\Thinking\Support\SetRequest;
use App\Modules\Thinking\Support\Words;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A question that asks to see a whole set gets all of it, in any store (Sela Meir, 20.10): "תציג לי
 * את ספריית שיבולת" showed two subscriptions instead of the library's books; "תן לי את כל ספרי
 * החטופים" missed Omer Shem Tov's book, whose description alone says he was taken; "איזה ספר מדע
 * בדיוני יש לכם?" in a store with none is offered Dracula as what it is, a horror classic.
 */
final class ProductSetsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array{system: string, user: array<string, mixed>, provider: AiProviderName}> */
    public array $calls = [];

    /** @var Closure(array<string, mixed>, Closure(string): ?string): array<string, mixed> what the picker answers */
    public Closure $picker;

    /** @var Closure(array<string, mixed>, Closure(string): ?string): array<string, mixed> what the checker answers */
    public Closure $checker;

    /** @var array<string, CatalogCategory> */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        $this->picker = fn (): array => ['matches' => [], 'title' => '', 'near' => [], 'near_title' => '', 'ask' => ['question' => '', 'options' => []]];
        $this->checker = fn (): array => ['wrong' => [], 'ask_ok' => true];

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly ProductSetsTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $input = (array) json_decode($user, true);
                $this->test->calls[] = ['system' => $system, 'user' => $input, 'provider' => $provider];
                $ref = fn (string $title): ?string => collect($input['candidates'] ?? $input['chosen'] ?? [])->firstWhere('title', $title)['id'] ?? null;

                return new ModelReply((str_contains($system, 'You check') ? $this->test->checker : $this->test->picker)($input, $ref), 900, 40);
            }
        });
    }

    public function test_a_set_request_is_read_from_its_words(): void
    {
        $this->assertSame('ספרי החטופים', SetRequest::subject('תן לי את כל ספרי החטופים'));
        $this->assertSame('ספריית שיבולת', SetRequest::subject('תציג לי את ספריית שיבולת?'));
        $this->assertSame('ספר מדע בדיוני', SetRequest::subject('איזה ספר מדע בדיוני יש לכם?'));
        $this->assertSame('ספרי חטופים', SetRequest::subject('איזזה ספרי חטופים יש לכם?'), 'a letter typed twice');
        $this->assertSame(SetRequest::key('ספרי החטופים'), SetRequest::key('חטופים ספרי'), 'the article and the order aside, one set');
        $this->assertSame('dresses', SetRequest::subject('show me all the dresses'));
        $this->assertSame('linen dresses', SetRequest::subject('which linen dresses do you have?'));
        $this->assertNull(SetRequest::subject('על מה הספר החטוף?'), 'a question about one book');
        $this->assertNull(SetRequest::subject('כמה עולה מנוי שיבולת?'));
        $this->assertNull(SetRequest::subject('איזה גודל מתאים לי?'), 'which, but not which the store has');
        $this->assertNull(SetRequest::subject('מה הכי זול מכל הספרים?'), 'which is cheapest is the assistant\'s');

        $this->assertTrue(Words::has(Words::searchable('עומר שם טוב נחטף לעזה'), Words::stems('החטופים')));
        $this->assertTrue(Words::has(Words::searchable('חטוף - אלי שרעבי'), Words::stems('החטופים')));
        $this->assertTrue(Words::has(Words::searchable('ספרים'), Words::stems('ספרי')));
        $this->assertTrue(Words::has(Words::searchable('טבעת עם אבן אדומה'), Words::stems('אדומות')));
        $this->assertFalse(Words::has(Words::searchable('לוחמי המילואים של הגדוד'), Words::stems('החטופים')));
    }

    public function test_a_category_the_store_keeps_is_the_whole_set_by_code_and_a_large_one_offers_its_parts(): void
    {
        $this->books();
        foreach (['5001' => 'הקרב על המערב', '5002' => 'צומת סואץ', '5003' => 'ערב הסעודית', '5004' => 'מדינה יהודית אחת', '5005' => 'אימפריה'] as $id => $title) {
            $this->product($id, $title, 'ספר בספריית שיבולת.', ['ספרים', 'ספריית שיבולת', $id <= '5002' ? 'ביוגרפיות' : 'כלכלה']);
        }
        Settings::set('thinking.focus_over', 6, $this->shop->id);

        $result = $this->ask('תציג לי את ספריית שיבולת?');

        $this->assertSame('answered', $result['outcome']);
        $this->assertSame('code', $result['from']);
        $this->assertCount(7, $result['set']['ids']);
        $this->assertSame(__('thinking::sets.group', ['count' => 7, 'name' => 'ספריית שיבולת']), $result['answer']);
        $this->assertSame('https://store.test/c/ספריית שיבולת', $result['page']['url']);
        $this->assertSame('ספריית שיבולת', $result['set']['search'], 'the store\'s search for more');
        $this->assertSame(['question' => __('thinking::sets.focus'), 'options' => ['כלכלה', 'ביוגרפיות']], $result['set']['ask'], 'the parts of the set, never "ספרים" that holds all of it');

        // an option pressed: the products in both, by code
        $narrowed = $this->ask('תציג לי את ספריית שיבולת ביוגרפיות');
        $this->assertSame(['5001', '5002'], $narrowed['set']['ids']);
        $this->assertSame('code', $narrowed['from']);
        $this->assertSame([], $this->calls, 'no model');
    }

    public function test_a_set_only_meaning_holds_is_chosen_by_one_family_and_checked_by_the_other(): void
    {
        $this->books();
        Settings::set('thinking.whole_catalog_max', 0, $this->shop->id);
        $this->picker = fn (array $in, Closure $ref): array => ['matches' => array_values(array_filter([$ref('חטוף - אלי שרעבי'), $ref('מפגש עם עצמי בגיהינום'), $ref('תחנות 7'), 'c99'])), 'title' => 'ספרים של חטופים ששבו', 'near' => [], 'near_title' => '', 'ask' => ['question' => '', 'options' => []]];
        // the picker takes the brigade's book too; the checker of the other family takes it out
        $this->checker = fn (array $in, Closure $ref): array => ['wrong' => [['id' => $ref('תחנות 7'), 'why' => 'קרבות החטיבה, לא חטופים'], ['id' => 'c77', 'why' => 'לא נשלח']], 'ask_ok' => true];

        $result = $this->ask('תן לי את כל ספרי החטופים');

        $this->assertSame('model', $result['from']);
        $this->assertSame(['4001', '4002'], $result['set']['ids'], 'an id not given is dropped');
        $this->assertSame('ספרים של חטופים ששבו (2):', $result['answer']);
        $this->assertFalse($result['set']['near']);
        $this->assertNull($result['set']['search'], 'chosen from the whole store: the search\'s words would only bring what shares them');
        $this->assertCount(2, $this->calls);
        $this->assertNotSame($this->calls[0]['provider']->family(), $this->calls[1]['provider']->family());
        $titles = array_column($this->calls[0]['user']['candidates'], 'title');
        $this->assertContains('מפגש עם עצמי בגיהינום', $titles, 'found by its description: "נחטף"');
        $this->assertNotContains('חברות ברזל', $titles);
        $run = Run::query()->where('action', FindProductSet::ACTION)->sole();
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(['תחנות 7 — קרבות החטיבה, לא חטופים'], $run->output['taken_out'], 'why each was taken out, for the operator');

        // asked again: the set kept, no model
        $again = $this->ask('תן לי את כל ספרי החטופים');
        $this->assertSame('bank', $again['from']);
        $this->assertSame(['4001', '4002'], $again['set']['ids']);
        $this->assertCount(2, $this->calls);

        // a new book about the hostages: the products to choose from changed, so it is chosen again
        $this->product('4005', 'הביתה', 'סיפורן של משפחות החטופים.', ['ספרים']);
        $this->ask('תן לי את כל ספרי החטופים');
        $this->assertCount(4, $this->calls);
        $this->assertSame(1, app(TenantContext::class)->run($this->shop->id, fn () => ProductSet::query()->count()));
    }

    public function test_in_a_small_store_the_whole_catalogue_is_read_and_the_nearest_is_offered_as_what_it_is(): void
    {
        $this->books();
        $this->product('6001', 'דרקולה', 'עורך דין בריטי מגיע לטרנסילבניה. ספר האימה הקלאסי.', ['ספרים']);
        $this->picker = fn (array $in, Closure $ref): array => ['matches' => [], 'title' => '', 'near' => [$ref('דרקולה')], 'near_title' => 'ספרות אימה קלאסית', 'ask' => ['question' => '', 'options' => []]];

        $result = $this->ask('איזה ספר מדע בדיוני יש לכם?');

        $this->assertCount(7, $this->calls[0]['user']['candidates'], 'every product, though none says "מדע בדיוני"');
        $this->assertSame(['6001'], $result['set']['ids']);
        $this->assertTrue($result['set']['near']);
        $this->assertSame(__('thinking::sets.near', ['subject' => 'ספר מדע בדיוני', 'title' => 'ספרות אימה קלאסית']), $result['answer']);
        $this->assertSame('near', $this->calls[1]['user']['chosen'][0]['as']);
        $this->assertStringContainsString('טרנסילבניה', $this->calls[1]['user']['chosen'][0]['text'], 'the checker reads it whole');
    }

    public function test_a_store_with_none_of_it_says_so_and_offers_the_team(): void
    {
        $this->books();

        $result = $this->ask('איזה ספרי בישול יש לכם?');

        $this->assertSame('no_info', $result['outcome']);
        $this->assertSame(__('thinking::sets.none', ['subject' => 'ספרי בישול']), $result['not_found']);
        $this->assertArrayHasKey('no_answer', $result);
        $this->assertFalse($this->calls[0]['user']['candidates'][0]['says']);
        $this->assertCount(1, $this->calls, 'nothing chosen: nothing to check');
    }

    public function test_a_broad_request_in_a_jewellery_store_asks_one_question_the_checker_let_through(): void
    {
        $this->product('7001', 'טבעת סוליטר זהב לבן', 'טבעת אירוסין.', ['טבעות'], [['name' => 'מתכת', 'values' => ['זהב לבן']]]);
        $this->product('7002', 'טבעת נישואין קלאסית', 'טבעת זהב צהוב.', ['טבעות'], [['name' => 'מתכת', 'values' => ['זהב צהוב']]]);
        $this->product('7003', 'עגילי פנינה', 'עגילים צמודים.', ['עגילים']);
        // what the scan read from the ring's picture, kept; one it superseded is not
        foreach ([['red', 'approved'], ['green', 'superseded']] as [$value, $status]) {
            app(TenantContext::class)->run($this->shop->id, fn () => EnrichmentFact::query()->create([
                'shop_id' => $this->shop->id, 'product_id' => CatalogProduct::query()->where('external_id', '7001')->value('id'), 'kind' => 'spec', 'key' => 'stone_color',
                'value_text' => $value, 'origin' => 'model', 'status' => $status, 'input_hash' => 'x',
            ]));
        }
        $this->picker = fn (array $in, Closure $ref): array => ['matches' => [$ref('טבעת סוליטר זהב לבן'), $ref('טבעת נישואין קלאסית')], 'title' => 'טבעות לחתונה', 'near' => [], 'near_title' => '',
            'ask' => ['question' => 'לאירוסין או לנישואין?', 'options' => ['אירוסין', 'נישואין', 'אירוסין']]];

        $result = $this->ask('תציג לי טבעות לחתונה');

        $this->assertSame(['7001', '7002'], $result['set']['ids']);
        $this->assertSame(['question' => 'לאירוסין או לנישואין?', 'options' => ['אירוסין', 'נישואין']], $result['set']['ask']);
        $this->assertSame(['מתכת: זהב לבן', 'stone_color: red'], collect($this->calls[0]['user']['candidates'])->firstWhere('title', 'טבעת סוליטר זהב לבן')['attributes'], 'the store\'s fields and what the scan kept reach the model');

        // the other family finds the question unhelpful: the products stay, the question goes
        ProductSet::query()->withoutGlobalScopes()->delete();
        $this->checker = fn (): array => ['wrong' => [], 'ask_ok' => false];
        $this->assertNull($this->ask('תציג לי טבעות לחתונה')['set']['ask']);
    }

    /** @return array<string, mixed> */
    private function ask(string $question): array
    {
        return app(AnswerSiteQuestion::class)->handle($this->shop->id, $question, 'visitor-1');
    }

    private function books(): void
    {
        $this->product('2666', 'מנוי שיבולת', 'מנוי חודשי לספרי הספרייה.', ['ספרים', 'ספריית שיבולת']);
        $this->product('3001', 'אמריקה השנייה', 'על ערים אמריקאיות שהתרוקנו.', ['ספרים', 'ספריית שיבולת']);
        $this->product('4001', 'חטוף - אלי שרעבי', '491 יום שרד אלי שרעבי בשבי החמאס.', ['ספרים']);
        $this->product('4002', 'מפגש עם עצמי בגיהינום', 'עומר שם טוב נחטף לעזה מפסטיבל נובה. במהלך השבי התגלגלתי לתא קטנטן.', ['ספרים']);
        $this->product('4003', 'תחנות 7', 'קרבותיה של חטיבה 7, עד הרגע בו אחרון חטופיה שוחרר מעזה.', ['ספרים']);
        $this->product('4004', 'חברות ברזל', 'לוחמי המילואים של הגדוד בבוקר השבת.', ['ספרים']);
    }

    /**
     * @param  list<string>  $categories
     * @param  list<array{name: string, values: list<string>}>  $attributes
     */
    private function product(string $id, string $title, string $description, array $categories, array $attributes = []): CatalogProduct
    {
        return app(TenantContext::class)->run($this->shop->id, function () use ($id, $title, $description, $categories, $attributes): CatalogProduct {
            $product = CatalogProduct::query()->create([
                'shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                'price' => '89.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => md5($title.$description),
                'payload' => ['title' => $title, 'short_description' => '', 'description' => '<p>'.$description.'</p>', 'attributes' => $attributes],
            ]);
            foreach ($categories as $name) {
                $this->categories[$name] ??= CatalogCategory::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) (count($this->categories) + 1), 'name' => $name, 'path' => [$name], 'depth' => 0,
                    'product_count' => 1, 'url' => 'https://store.test/c/'.$name, 'hash' => md5($name),
                ]);
            }
            $product->categories()->attach(array_map(fn (string $name): string => $this->categories[$name]->id, $categories));

            return $product;
        });
    }
}
