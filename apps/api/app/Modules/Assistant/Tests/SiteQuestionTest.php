<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\MendShownQuestions;
use App\Modules\Assistant\Contracts\ProductsDescribed;
use App\Modules\Assistant\Contracts\ProductsNamed;
use App\Modules\Assistant\Contracts\ProductsNear;
use App\Modules\Assistant\Contracts\ProductsPopular;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\AnswerContext;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogProductChoice;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A question typed in the search box: answered from the store's own pages, written by one family,
 * checked by the other, shown with the pages it came from, and kept for the next shopper.
 */
final class SiteQuestionTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'lat_cccccccccccccccccccccccccccccccccccccccccccccccc';

    private const VID = 'anon-visitor1234567890abcd';

    /** @var list<array{provider: AiProviderName, model: string, user: string, max: int}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    /** @var list<array<string, mixed>> what the fake passage search answers */
    public array $passages = [];

    /** @var array<string, list<array<string, mixed>>> what it answers for one wording, instead */
    public array $byText = [];

    /** @var list<string> the wordings it was asked with */
    public array $searched = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The topic check is its own model call, tested in GuardTest; these test what follows it.
        Features::override('assistant.site_scope', false);

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $product = $this->product('31538', 'בורג נירוסטה לדק 5X50', 'בורג לעץ קשה.', []);
        $guide = $this->article('600', 'כמה ברגים צריך למטר דק', 'למטר מרובע של דק צריך כ-30 ברגים, שניים בכל מפגש של קרש עם קורה.');
        $this->inShop(fn () => $guide->forceFill(['url' => 'https://store.test/?p=600'])->save());
        $this->passages = [
            ['source' => 'content', 'source_id' => $guide->id, 'external_id' => '600', 'title' => 'כמה ברגים צריך למטר דק', 'text' => 'למטר מרובע של דק צריך כ-30 ברגים, שניים בכל מפגש של קרש עם קורה.', 'similarity' => 0.71],
            ['source' => 'product', 'source_id' => $product->id, 'external_id' => '31538', 'title' => 'בורג נירוסטה לדק 5X50', 'text' => 'בורג לעץ קשה.', 'similarity' => 0.52],
            ['source' => 'product', 'source_id' => $product->id, 'external_id' => '31538', 'title' => 'בורג נירוסטה לדק 5X50', 'text' => 'משהו רחוק.', 'similarity' => 0.1],
        ];

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly SiteQuestionTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'model' => $model, 'user' => $user, 'max' => $maxOutputTokens];
                $reply = array_shift($this->test->replies) ?? [];

                // A provider that refuses the call, as Anthropic did when its key ran out.
                if (isset($reply['__fails'])) {
                    throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, new \RuntimeException((string) $reply['__fails']));
                }

                return new ModelReply($reply, 700, 120);
            }
        });
        $this->app->instance(Passages::class, new class($test) implements Passages
        {
            public function __construct(private readonly SiteQuestionTest $test) {}

            public function near(string $shopId, string $text, array $sources, int $limit): array
            {
                $this->test->searched[] = $text;

                return array_slice($this->test->byText[$text] ?? $this->test->passages, 0, $limit);
            }
        });
    }

    public function test_one_family_writes_from_the_pages_the_other_checks_and_the_answer_is_kept(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר מרובע, שניים בכל מפגש של קרש עם קורה.', 'refs' => ['s1', 's9']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];

        $data = $this->ask('כמה ברגים צריך למטר דק?')->assertOk()->json('data');

        $this->assertSame('answered', $data['outcome']);
        $this->assertSame('model', $data['from']);
        $this->assertSame([['title' => 'כמה ברגים צריך למטר דק', 'url' => 'https://store.test/?p=600', 'type' => 'content']], $data['sources'], 'only the page it cited, as the shopper can open it');
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column($this->calls, 'provider'));
        $this->assertStringNotContainsString('משהו רחוק', $this->calls[0]['user'], 'a piece below the nearness floor is never sent');
        $this->assertStringContainsString('30 ברגים', $this->calls[1]['user'], 'the checker gets the cited text');
        $this->assertGreaterThan(0, (float) Run::query()->where('agent', AnswerSiteQuestion::AGENT)->sole()->cost_usd);

        $again = $this->ask('כמה ברגים צריך למטר דק')->json('data');
        $this->assertSame(['bank', $data['sources']], [$again['from'], $again['sources']]);
        $this->assertCount(2, $this->calls, 'the same question costs nothing the second time');

        $saved = $this->inShop(fn () => AnswerSiteQuestion::siteAnswers()->sole());
        $this->assertNull($saved->product_id);
        $this->assertNull($saved->content_id);
    }

    public function test_a_writer_that_found_nothing_and_wrote_no_answer_still_says_why_to_the_shopper(): void
    {
        // Sela Meir, "איזה ספר הכי מומלץ?": no answer, no refs, its line dropped as "no answer"
        $line = 'אין באתר המלצה אחת שמסומנת כ"הכי מומלץ" מבין הספרים האלה. אם תרצה, צוות החנות ישמח לעזור לך לבחור ספר לפי נושא או עניין.';
        $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => [], 'not_found' => $line, 'fine' => true]];

        $data = $this->ask('איזה ספר הכי מומלץ?')->json('data');
        $asked = count($this->calls);

        $this->assertSame($line, $data['not_found']);
        $this->assertSame($line, $this->ask('איזה ספר הכי מומלץ?')->json('data.not_found'), 'kept with its line: the next one is told it without a model');
        $this->assertCount($asked, $this->calls);
    }

    public function test_the_checker_is_haiku_5_5_with_room_to_think_and_what_it_did_is_kept(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר מרובע.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];
        $this->ask('כמה ברגים צריך למטר דק?');

        $this->assertSame(['claude-haiku-5-5', 3000], [$this->calls[1]['model'], $this->calls[1]['max']], 'its thinking counts as output: a check with its fix and three products comes near 1,500');
        $check = Run::query()->where('agent', AnswerSiteQuestion::AGENT)->sole()->output['check'];
        $this->assertEquals(['ran' => true, 'type' => 'store_info', 'verdict' => 'kept', 'model' => 'claude-haiku-5-5', 'in' => 700, 'out' => 120],
            array_intersect_key($check, array_flip(['ran', 'type', 'verdict', 'model', 'in', 'out'])));
        $this->assertSame(0.00013, $check['cost_usd'], '700 in at $0.10 and 120 out at $0.50 a million');
        $this->assertIsInt($check['ms']);

        // The checker refuses what the page does not say.
        $this->replies = [
            ['found' => true, 'answer' => 'שניים בכל מפגש, ועדיף נירוסטה ליד הים.', 'refs' => ['s1']],
            ['supported' => false, 'on_topic' => true, 'safe' => true],
        ];
        $this->ask('איך מבריגים דק?');

        // Code refuses first, and the checker is not asked.
        $this->replies = [['found' => true, 'answer' => 'צריך 45 ברגים למטר.', 'refs' => ['s1']]];
        $this->ask('כמה ברגים צריך?');

        $checks = Run::query()->where('agent', AnswerSiteQuestion::AGENT)->orderBy('id')->get()->map(fn (Run $r): array => $r->output['check'])->all();
        $this->assertSame('rejected', $checks[1]['verdict']);
        $this->assertSame(['ran' => false, 'trimmed' => 1, 'type' => 'store_info', 'skipped' => 'number_not_in_the_pages'], $checks[2]);

        $this->assertSame(0, Artisan::call('assistant:checker-report'));
        $report = Artisan::output();
        $this->assertMatchesRegularExpression('/store_info · claude-haiku-5-5\s*\|\s*2\s*\|\s*2\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|\s*50%/u', $report,
            'two checked, one kept and one refused: half changed');
        $this->assertMatchesRegularExpression('/store_info · —\s*\|\s*1\s*\|\s*0\s*\|\s*1\s*\|/u', $report, 'the one code refused, never checked');
    }

    public function test_what_the_pages_do_not_say_is_never_shown(): void
    {
        // A number the cited pages do not have: refused in code, the checker is not asked.
        $this->replies = [['found' => true, 'answer' => 'צריך 45 ברגים למטר.', 'refs' => ['s1']]];
        $this->assertSame('no_info', $this->ask('כמה ברגים צריך?')->json('data.outcome'));
        $this->assertCount(1, $this->calls);

        // The checker finds something the pages do not say.
        $this->replies = [
            ['found' => true, 'answer' => 'שניים בכל מפגש, ועדיף נירוסטה ליד הים.', 'refs' => ['s1']],
            ['supported' => false, 'on_topic' => true, 'safe' => true],
        ];
        $data = $this->ask('איך מבריגים דק?')->json('data');
        $this->assertSame(['no_info', __('assistant::answers.site.no_info', [], 'he')], [$data['outcome'], $data['answer']]);

        // The writer cites nothing it was given.
        $this->replies = [['found' => true, 'answer' => 'כן.', 'refs' => ['s7']]];
        $this->assertSame('no_info', $this->ask('יש לכם ברגים?')->json('data.outcome'));
    }

    public function test_an_answer_that_recommends_products_is_written_again_once_a_product_is_added(): void
    {
        $answer = fn (): array => [
            ['found' => true, 'answer' => 'לדק כדאי בורג נירוסטה.', 'refs' => ['s2'], 'picks' => [['ref' => 'p1', 'why' => 'מתאים לדק']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->replies = $answer();
        $this->assertSame('model', $this->ask('איזה בורג מתאים לדק?')->json('data.from'));
        $this->assertSame('bank', $this->ask('איזה בורג מתאים לדק?')->json('data.from'), 'saved: the next shopper costs nothing');

        // a store's info answer stays; one that recommends products is asked again, once, when a product joins
        $this->travel(1)->minutes();
        $this->product('31600', 'בורג נירוסטה לדק 5X60', 'בורג ארוך לעץ קשה.', []);
        $this->travel(1)->minutes();
        $this->replies = $answer();
        $this->assertSame('model', $this->ask('איזה בורג מתאים לדק?')->json('data.from'), 'a new product may answer it better');
        $this->assertSame('bank', $this->ask('איזה בורג מתאים לדק?')->json('data.from'));
    }

    public function test_the_writers_refs_never_reach_the_shopper(): void
    {
        // Seen on Gueta: "(p5)" beside a product's name, kept by the checker and shown. The ref is
        // dropped where it only names the product; the answer stands.
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר (s1), ובורג נירוסטה (p1) מתאים לדק.', 'refs' => ['s1'], 'picks' => [['ref' => 'p1', 'why' => 'מתאים לדק - p1']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $data = $this->askWith('כמה ברגים צריך למטר דק?', ['31538'])->json('data');
        $this->assertSame('בערך 30 ברגים למטר, ובורג נירוסטה מתאים לדק.', $data['answer']);
        $this->assertSame('מתאים לדק', $data['picks'][0]['why']);
        $this->assertStringNotContainsString('p1', json_decode($this->calls[1]['user'], true)['answer'], 'the checker reads the answer as the shopper will');

        // A ref used as a word ("p3 is cheaper") names nothing the shopper knows: refused before any checker.
        $this->replies = [['found' => true, 'answer' => 'p3 זול יותר ומתאים.', 'refs' => ['s1'], 'picks' => [['ref' => 'p1', 'why' => 'זול יותר מ-p3']]]];
        $data = $this->askWith('איזה בורג מתאים לדק?', ['31538'])->json('data');
        $this->assertSame('no_match', $data['outcome']);
        $this->assertSame(['answer_site' => 2, 'verify_site' => 1], array_count_values(array_map(fn (array $c): string => str_contains($c['user'], '"answer"') && str_contains($c['user'], '"passages"') && ! str_contains($c['user'], '"products"') ? 'verify_site' : 'answer_site', $this->calls)), 'no checker for an answer code refused: two writers asked, one checker in all');

        // Model numbers and sizes are not refs.
        $this->assertSame('ביט PH2 של WERA, 25 מ"מ, למברגה 12V', AnswerSiteQuestion::withoutRefs('ביט PH2 של WERA, 25 מ"מ, למברגה 12V'));
        // A ref in Hebrew letters is a ref too (Grok, Rocks 18.11): "(פ10)" beside the product it names
        $this->assertSame('- טבעת וינטאג פרח משובצת יהלום – עיצוב קלאסי.', AnswerSiteQuestion::withoutRefs('- טבעת וינטאג פרח משובצת יהלום (פ10) – עיצוב קלאסי.'));
        $this->assertSame('מדריך הטיפול שלנו.', AnswerSiteQuestion::withoutRefs('מדריך הטיפול שלנו (ס2).'));
        $this->assertFalse(AnswerSiteQuestion::hasRef('ביט PH2 של WERA 12V'));
    }

    public function test_no_answer_from_its_own_words_the_question_is_asked_in_other_ways_and_answered(): void
    {
        $policy = $this->article('700', 'תנאי רכישה', 'ניתן להחזיר מוצר תוך 14 יום מיום הקבלה ולקבל זיכוי מלא.');
        $this->byText['מדיניות החזרות'] = [['source' => 'content', 'source_id' => $policy->id, 'external_id' => '700', 'title' => 'תנאי רכישה', 'text' => 'ניתן להחזיר מוצר תוך 14 יום מיום הקבלה ולקבל זיכוי מלא.', 'similarity' => 0.82]];
        $this->replies = [
            ['found' => false, 'answer' => '', 'refs' => []],
            ['found' => true, 'answer' => 'אפשר להחזיר מוצר תוך 14 יום מיום הקבלה ולקבל זיכוי מלא.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];

        $data = $this->ask('מה מדיניות ההחזרים?')->assertOk()->json('data');

        $this->assertSame('answered', $data['outcome'], 'the second look found the policy');
        $this->assertSame('תנאי רכישה', $data['sources'][0]['title']);
        $this->assertContains('מדיניות החזרות', $this->searched, 'its topic as stores write it, from code');
        $this->assertCount(3, $this->calls, 'no model was needed for the other wordings: the writer twice and the checker');
        $this->assertStringNotContainsString('ניתן להחזיר', $this->calls[0]['user'], 'the first look had not found it');
        $this->assertSame(AssistantAnswer::ANSWERED, $this->inShop(fn () => AnswerSiteQuestion::siteAnswers()->sole()->outcome), 'kept as answered for the next shopper');
    }

    public function test_a_fee_or_a_contact_the_cited_policy_states_is_the_stores_word_one_made_up_is_not(): void
    {
        $policy = $this->article('702', 'מדיניות החזרות', 'ניתן לבטל תוך 14 יום. דמי ביטול של עד 5% או 100 ₪, לפי הנמוך. לביטול: 03-5551234 או shop@rocks.test.');
        $this->passages = [['source' => 'content', 'source_id' => $policy->id, 'external_id' => '702', 'title' => 'מדיניות החזרות', 'text' => 'ניתן לבטל תוך 14 יום. דמי ביטול של עד 5% או 100 ₪, לפי הנמוך. לביטול: 03-5551234 או shop@rocks.test.', 'similarity' => 0.8]];
        $this->replies = [
            ['found' => true, 'answer' => 'אפשר לבטל תוך 14 יום, בדמי ביטול של עד 5% או 100 ₪. לביטול: 03-5551234 או shop@rocks.test.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];

        $this->assertSame('answered', $this->ask('מה מדיניות הביטולים?')->json('data.outcome'), 'the fee and the contacts are the policy\'s own');

        $prepared = ['byRef' => ['s1' => $this->passages[0]], 'productsByRef' => [], 'forModel' => []];
        $screen = fn (string $answer): ?string => app(AnswerSiteQuestion::class)->screen(['answer' => $answer, 'refs' => ['s1']], $prepared, 'מה דמי הביטול?', 'fixed')['refusal'];
        $this->assertSame('price', $screen('דמי הביטול הם 250 ₪.'), 'a sum the page does not have');
        $this->assertSame('contact_details', $screen('התקשרו ל-054-9998877.'), 'a number the page does not have');
        $this->assertNull($screen('דמי הביטול עד 100 ₪.'));
    }

    public function test_a_not_found_kept_before_the_second_look_is_asked_again(): void
    {
        // Kept "not on the site" by the first look alone, before the second look was there.
        $this->passages = [];
        Features::override('assistant.second_look', false);
        $this->assertSame('no_info', $this->ask('מה מדיניות ההחזרים?')->json('data.outcome'));
        Features::override('assistant.second_look', true);

        $policy = $this->article('701', 'תנאי רכישה', 'ניתן להחזיר מוצר תוך 14 יום מיום הקבלה ולקבל זיכוי מלא.');
        $this->byText['מדיניות החזרות'] = [['source' => 'content', 'source_id' => $policy->id, 'external_id' => '701', 'title' => 'תנאי רכישה', 'text' => 'ניתן להחזיר מוצר תוך 14 יום מיום הקבלה ולקבל זיכוי מלא.', 'similarity' => 0.82]];
        $this->inShop(fn () => CatalogContent::query()->update(['updated_at' => now()->subDay()]));
        $this->replies = [
            ['found' => true, 'answer' => 'אפשר להחזיר מוצר תוך 14 יום מיום הקבלה ולקבל זיכוי מלא.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];

        $this->assertSame('answered', $this->ask('מה מדיניות ההחזרים?')->json('data.outcome'), 'not served from memory: looked for again, the wider way');
    }

    public function test_a_not_found_is_asked_again_once_the_scan_learned_more_of_the_products(): void
    {
        // Seen on Sela: "show me fantasy" kept as not on the site, and served from memory ever after.
        $this->passages = [];
        Features::override('assistant.second_look', false);
        $this->assertSame('no_info', $this->ask('יש לכם ספרי פנטזיה?')->json('data.outcome'));
        $guide = $this->article('703', 'ספרי פנטזיה', 'מבחר ספרי פנטזיה לנוער ולמבוגרים.');
        $this->inShop(fn () => CatalogContent::query()->update(['updated_at' => now()->subDay()]));
        $this->passages = [['source' => 'content', 'source_id' => $guide->id, 'external_id' => '703', 'title' => 'ספרי פנטזיה', 'text' => 'מבחר ספרי פנטזיה לנוער ולמבוגרים.', 'similarity' => 0.8]];
        $this->replies = [
            ['found' => true, 'answer' => 'כן, יש מבחר ספרי פנטזיה לנוער ולמבוגרים.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];

        $this->assertSame('no_info', $this->ask('יש לכם ספרי פנטזיה?')->json('data.outcome'), 'nothing new in the store: from memory');
        $this->assertCount(0, $this->calls, 'no model');

        // The scan learns the books' genre: the same question is looked at again.
        $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => CatalogProduct::query()->value('id'), 'kind' => FactKind::Choice, 'key' => 'genre',
            'value_text' => 'פנטזיה', 'origin' => 'model', 'status' => FactStatus::Approved, 'input_hash' => str_repeat('a', 64),
        ]));
        $this->assertSame('answered', $this->ask('יש לכם ספרי פנטזיה?')->json('data.outcome'), 'asked again, once the scan learned more');
    }

    public function test_when_code_has_no_other_wording_a_small_model_writes_them_once(): void
    {
        $this->replies = [
            ['found' => false, 'answer' => '', 'refs' => []],
            ['questions' => ['איזה צבע מתאים לי', 'https://evil.test/x', 'מה הכי מתאים לעור בהיר']],
            ['found' => false, 'answer' => '', 'refs' => []],
        ];

        $this->assertSame('no_info', $this->ask('מה יתאים לי?')->json('data.outcome'), 'still nothing: the first answer stands');
        $this->assertContains('מה הכי מתאים לעור בהיר', $this->searched);
        $this->assertNotContains('https://evil.test/x', $this->searched, 'a link is never searched with');
        $this->assertSame(1, Run::query()->where('action', AnswerSiteQuestion::SIMILAR_ACTION)->count(), 'its own run, with its cost');

        $this->assertSame(['איזה צבע מתאים לי', 'מה הכי מתאים לעור בהיר'], $this->inShop(fn () => app(AnswerSiteQuestion::class)->similarQuestions($this->shop->id, 'מה יתאים לי?', 'he')), 'kept: never asked twice');
        $this->assertCount(3, $this->calls);

        Features::override('assistant.second_look', false);
        $this->replies = [['found' => false, 'answer' => '', 'refs' => []]];
        $this->ask('ומה עם צבע?');
        $this->assertCount(4, $this->calls, 'turned off: one look');
    }

    public function test_a_question_about_a_product_it_names_reads_that_products_own_description(): void
    {
        $this->product('900', 'חטוף - אלי שרעבי', 'סיפורו של אלי שרעבי, שנחטף לעזה ב-7 באוקטובר ושב אחרי 491 ימים בשבי.', []);
        foreach (['901', '902', '903', '904'] as $id) {
            $this->product($id, "ספר היסטוריה $id", 'ספר.', []);
        }
        $this->replies = [
            ['found' => true, 'answer' => 'זה סיפורו של אלי שרעבי, שנחטף לעזה ושב אחרי 491 ימים.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'הספר עצמו']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];

        $this->ask('על מה הספר החטוף?');

        $this->assertStringContainsString('491 ימים בשבי', $this->calls[0]['user'], 'the book the question names, with its own description');
        $this->assertStringNotContainsString('ספר היסטוריה', $this->calls[0]['user'], '"ספר" names every book: none of them');
    }

    public function test_the_book_a_question_names_best_is_the_one_described_never_one_sharing_a_word(): void
    {
        $this->product('900', 'חטוף - אלי שרעבי', 'סיפורו של אלי שרעבי, שנחטף לעזה ושב אחרי 491 ימים בשבי.', []);
        $this->product('910', 'ספר הזוהר', 'פירוש לספר הזוהר.', []);
        $this->product('911', 'הספרנית', 'רומן על ספרנית בעיר קטנה.', []);
        $this->product('920', 'המדינה שבדרך', 'חזון לעתידה של ישראל: מהישרדות לתור זהב.', []);
        $this->product('921', 'המדינה הזאת', 'סוד ההישרדות של קהילות ישראל באלפיים שנות גלות.', []);
        $answer = ['found' => true, 'answer' => 'תשובה.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'הספר']]];
        $checked = ['supported' => true, 'on_topic' => true, 'safe' => true];

        $this->replies = [$answer, $checked];
        $this->ask('על מה הספר החטוף?');
        $this->assertStringContainsString('491 ימים בשבי', $this->calls[0]['user']);
        $this->assertStringNotContainsString('פירוש לספר הזוהר', $this->calls[0]['user'], '"ספר" names no book');

        $this->calls = [];
        $this->replies = [$answer, $checked];
        $this->ask('על מה הספר המדינה שבדרך?');
        $this->assertStringContainsString('לתור זהב', $this->calls[0]['user'], 'the book both its words name');
        $this->assertStringNotContainsString('אלפיים שנות גלות', $this->calls[0]['user'], 'not the one sharing only "המדינה"');

        // A word only one title holds, in its subtitle ("הביוגרפיה"), against the name the question says whole ("ביבי").
        $this->product('930', 'ביבי: סיפור חיי', 'האוטוביוגרפיה של בנימין נתניהו, מילדותו בירושלים ועד ראשות הממשלה.', []);
        $this->product('931', 'אשת הברזל - הביוגרפיה של מרגרט תאצ\'ר', 'חייה של ראשת ממשלת בריטניה.', []);
        $this->product('932', 'מארז המנהיגים הגדולים + ביבי', 'מארז של ספרי מנהיגים.', []);
        $this->calls = [];
        $this->replies = [$answer, $checked];
        $this->ask('על מה הספר הביוגרפיה של ביבי?');
        $this->assertStringContainsString('ועד ראשות הממשלה', $this->calls[0]['user'], 'the book named "ביבי"');
        $this->assertStringNotContainsString('ראשת ממשלת בריטניה', $this->calls[0]['user'], 'not the biography it only shares a subtitle word with');

        // The title's own "ה" left out by the question.
        $this->product('940', 'העדן', 'רומן על קיבוץ בעמק ועל שלושה דורות.', []);
        $this->calls = [];
        $this->replies = [$answer, $checked];
        $this->ask('על מה הספר עדן?');
        $this->assertStringContainsString('שלושה דורות', $this->calls[0]['user'], '"עדן" names "העדן"');
    }

    public function test_a_budget_names_no_product_whose_title_holds_the_same_number(): void
    {
        // Take Me Home, 9.11: "סל מתנה לתינוק עד 400 ש"ח" named the two shampoos of "400 מ"ל", and nothing the shopper
        // had seen reached the writer
        $this->product('700', 'שמפו אל דמע לתינוק 400 מ"ל', 'שמפו עדין.', [], ['price' => '29.90']);
        $this->product('701', 'שמפו 2 ב-1 400 מ"ל', 'שמפו וסבון.', [], ['price' => '29.90']);
        $this->product('702', 'שמיכת במבוק כדור פורח', 'שמיכה רכה לתינוק.', [], ['price' => '63.92']);
        $answer = ['found' => true, 'answer' => 'תשובה.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'מתנה']]];
        $checked = ['supported' => true, 'on_topic' => true, 'safe' => true];

        $this->replies = [$answer, $checked];
        $this->askWith('תרכיב לי סל מתנה עד 400 ש"ח', ['702']);
        $this->assertStringContainsString('שמיכת במבוק כדור פורח', $this->calls[0]['user'], 'what the shopper saw stays');

        // a number alone names nothing either; with a word of the title it still does
        $this->calls = [];
        $this->replies = [$answer, $checked];
        $this->askWith('יש משהו ב 400?', ['702']);
        $this->assertStringContainsString('שמיכת במבוק כדור פורח', $this->calls[0]['user']);

        $this->calls = [];
        $this->replies = [$answer, $checked];
        $this->askWith('ממה עשוי השמפו 2 ב-1?', ['702']);
        $this->assertStringContainsString('שמפו וסבון', $this->calls[0]['user'], 'named by its words');
    }

    public function test_nothing_near_the_question_asks_no_writer_and_is_asked_again_once_the_store_says_more(): void
    {
        $this->passages = [];

        $this->assertSame('no_info', $this->ask('יש משלוח לאילת?')->json('data.outcome'));
        $this->assertSame([], $this->calls);

        $this->passages = [['source' => 'content', 'source_id' => $this->inShop(fn () => CatalogContent::query()->value('id')), 'external_id' => '600', 'title' => 'משלוחים', 'text' => 'יש משלוח לכל הארץ, כולל אילת.', 'similarity' => 0.8]];
        $this->replies = [['found' => true, 'answer' => 'כן, יש משלוח לכל הארץ, כולל אילת.', 'refs' => ['s1']], ['supported' => true, 'on_topic' => true, 'safe' => true]];

        $this->assertSame('no_info', $this->ask('יש משלוח לאילת?')->json('data.outcome'), 'the same day: from memory');
        $this->travel((int) Settings::get('assistant.site_retry_days') + 1)->days();
        $this->assertSame('no_info', $this->ask('יש משלוח לאילת?')->json('data.outcome'), 'days passing say nothing new: still from memory, no model');
        $this->travel(1)->minutes();
        $this->inShop(fn () => CatalogContent::query()->update(['updated_at' => now()]));
        $data = $this->ask('יש משלוח לאילת?')->json('data');
        $this->assertSame('answered', $data['outcome'], 'the store wrote the page: asked again');
        // a question of the store's: the storefront offers no products "related" to its words (Vans QA, 16.11)
        $this->assertTrue($data['store_question'] ?? false);
    }

    public function test_a_writer_never_checks_its_own_family_and_a_page_answer_is_not_a_site_answer(): void
    {
        $productId = $this->inShop(fn () => CatalogProduct::query()->value('id'));
        $this->inShop(fn () => AssistantAnswer::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $productId, 'question_key' => Question::key('כמה ברגים צריך?'),
            'question' => 'כמה ברגים צריך?', 'answer' => 'על המוצר הזה.', 'outcome' => AssistantAnswer::ANSWERED, 'prompt_version' => 3, 'last_asked_at' => now(),
        ]));
        Settings::set('assistant.site_check_provider', 'openai');

        $this->assertSame('unavailable', $this->ask('כמה ברגים צריך?')->json('data.outcome'), 'the page answer is not served to the whole site');
        $this->assertSame([], $this->calls, 'stopped before any model was asked');
    }

    public function test_only_the_shop_own_site_may_ask(): void
    {
        $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: ['HTTP_ORIGIN' => 'https://other.test', 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['question' => 'שאלה', 'vid' => self::VID]))->assertForbidden();
        $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: ['HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['question' => 'שאלה', 'vid' => 'nobody']))->assertStatus(422);
    }

    public function test_an_answer_that_could_not_be_checked_is_never_shown_the_stores_own_words_are(): void
    {
        $this->passages = [['source' => 'content', 'source_id' => $this->inShop(fn () => CatalogContent::query()->value('id')), 'external_id' => '600', 'title' => 'מדיניות החזרות',
            'text' => "ברוכים הבאים לחנות.\nניתן להחזיר מוצר תוך 14 יום מיום הקנייה, בתנאי שלא נעשה בו שינוי.\nהחלפה אפשרית תוך חודש.", 'similarity' => 0.8]];
        // The writer answers; the checker's provider refuses (its key ran out).
        $this->replies = [['found' => true, 'answer' => 'בטח, אפשר להחזיר תוך שנה!', 'refs' => ['s1']], ['__fails' => 'Anthropic Authentication Exception: invalid x-api-key']];

        $data = $this->ask('אפשר להחזיר מוצר?')->assertOk()->json('data');

        $this->assertSame('quoted', $data['outcome']);
        $this->assertSame('', $data['answer'], 'the unchecked words are never shown');
        $this->assertSame([['title' => 'כמה ברגים צריך למטר דק', 'url' => 'https://store.test/?p=600', 'text' => 'ניתן להחזיר מוצר תוך 14 יום מיום הקנייה, בתנאי שלא נעשה בו שינוי. החלפה אפשרית תוך חודש.']], $data['quotes'],
            'the store\'s own sentences about it, as they are, with its page; not its greeting');
        $this->assertSame(0, $this->inShop(fn () => AssistantAnswer::query()->count()), 'nothing kept for the next shopper');
        $run = Run::query()->where('agent', AnswerSiteQuestion::AGENT)->latest()->first();
        $this->assertSame('assistant::runs.site_unchecked', $run->summary_key);
    }

    public function test_a_store_question_asked_on_a_product_page_is_answered_from_the_stores_pages(): void
    {
        // "How long until it arrives?" on a product's page: its card does not say it and the product's writer
        // may not promise delivery, so it went "not found". Code reads it as a store question; the site's answerer takes it.
        $this->passages = [['source' => 'content', 'source_id' => $this->inShop(fn () => CatalogContent::query()->value('id')), 'external_id' => '600', 'title' => 'משלוחים', 'text' => 'המשלוח מגיע תוך 3 ימי עסקים.', 'similarity' => 0.8]];
        $this->replies = [['found' => true, 'answer' => 'המשלוח מגיע תוך 3 ימי עסקים.', 'refs' => ['s1']], ['supported' => true, 'on_topic' => true, 'safe' => true]];

        $answer = $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['id' => '31538', 'type' => 'product', 'question' => 'תוך כמה זמן זה מגיע?', 'vid' => self::VID, 'locale' => 'he'], JSON_UNESCAPED_UNICODE))->assertOk()->json('data');

        $this->assertSame(['answered', 'המשלוח מגיע תוך 3 ימי עסקים.'], [$answer['outcome'], $answer['answer']]);
        $this->assertSame(['assistant.site_answerer'], Run::query()->pluck('agent')->unique()->values()->all(), 'the product\'s writer is never asked');
    }

    /**
     * lets-review-test-4, 15.11: an English store's "Which snowboard is good for a beginner?" was answered in Hebrew, its
     * products' notes too. The writer is told the shopper's language (the question's own), an answer in another is asked
     * once more, and a second one is no answer: the team's line, in English. Never Hebrew by default.
     */
    public function test_an_english_question_is_answered_in_english_and_a_hebrew_one_in_hebrew(): void
    {
        // the storefront sent English, the question is English: written in Hebrew once, asked again, answered in English
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר.', 'refs' => ['s1']],
            ['found' => true, 'answer' => 'About 30 screws per square metre, two where each board meets a joist.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];
        $data = $this->askIn('How many screws do I need for a metre of decking?', 'en')->assertOk()->json('data');

        $this->assertSame('English', json_decode($this->calls[0]['user'], true)['language'] ?? null, 'the writer is told the language');
        $this->assertSame('answered', $data['outcome']);
        $this->assertStringStartsWith('About 30 screws', $data['answer']);
        $this->assertCount(3, $this->calls, 'written twice, checked once');

        // twice in Hebrew: no answer, and what the shopper is told is English
        $this->calls = [];
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר.', 'refs' => ['s1']],
            ['found' => true, 'answer' => 'שוב בעברית.', 'refs' => ['s1']],
        ];
        $data = $this->askIn('How many screws per metre of decking, roughly?', 'en')->assertOk()->json('data');
        $this->assertNotSame('answered', $data['outcome']);
        $this->assertDoesNotMatchRegularExpression('/\p{Hebrew}/u', (string) ($data['answer'] ?? ''), 'never Hebrew to an English question');

        // no locale sent at all: the question's own language still decides
        $this->calls = [];
        $this->replies = [
            ['found' => true, 'answer' => 'About 30 screws per square metre.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];
        $this->askIn('How many decking screws per metre?', null)->assertOk();
        $this->assertSame('English', json_decode($this->calls[0]['user'], true)['language'] ?? null);

        // a Hebrew question on a Hebrew store: Hebrew
        $this->calls = [];
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר מרובע, שניים בכל מפגש של קרש עם קורה.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];
        $data = $this->ask('כמה ברגים צריך למטר דק?')->assertOk()->json('data');
        $this->assertSame('Hebrew', json_decode($this->calls[0]['user'], true)['language'] ?? null);
        $this->assertSame('answered', $data['outcome']);
        $this->assertCount(2, $this->calls, 'in its language at once: no second writing');
    }

    public function test_the_v28_writer_keeps_up_to_six_picks_and_asks_back_with_choices(): void
    {
        // the comparison with Zipchat, 16.11: one or two products for a gift, no question to narrow it; the v28 writer gives
        // three to six of different kinds and a question with choices to tap; the released version keeps three, no choices
        foreach (['981' => 'טבעת זהב עדינה', '982' => 'שרשרת זהב דקה', '983' => 'עגילי פנינה', '984' => 'צמיד זהב', '985' => 'תליון לב', '986' => 'טבעת יהלום'] as $id => $title) {
            $this->product((string) $id, $title, 'תכשיט.', [], ['price' => '900.00', 'in_stock' => true]);
        }
        $reply = fn (): array => [
            ['found' => true, 'answer' => 'למתנה לאמא יש לנו כמה כיוונים יפים. מה היא אוהבת יותר?', 'refs' => [], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true,
                'picks' => array_map(fn (int $i): array => ['ref' => 'p'.$i, 'why' => 'מתנה יפה'], range(1, 6)), 'choices' => ['טבעות', 'שרשראות', 'עגילים']],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $shown = ['981', '982', '983', '984', '985', '986'];

        $this->replies = $reply();
        $v28 = $this->inShop(fn () => app(AnswerSiteQuestion::class)->replay($this->shop->id, 'מתנה לאמא', $shown, 28))['result'];
        $this->assertCount(6, $v28['picks']);
        $this->assertSame(['טבעות', 'שרשראות', 'עגילים'], $v28['choices'] ?? null);

        $this->calls = [];
        $this->replies = $reply();
        $released = $this->inShop(fn () => app(AnswerSiteQuestion::class)->replay($this->shop->id, 'מתנה לאמא', $shown))['result'];
        $this->assertCount(3, $released['picks'], 'the released writer keeps three');
        $this->assertArrayNotHasKey('choices', $released);
    }

    public function test_a_reply_about_sale_items_in_a_returns_conversation_is_not_a_list_of_products_on_sale(): void
    {
        // Naot, 16.11: "what about sale items?" after "can I return or exchange it?" was answered by code "15 on sale now"
        // more on sale than code ever lists by name, as at Naot: code's answer would be the whole set, "N on sale now"
        $shown = [];
        foreach (['Mia', 'Pixie', 'Acacia', 'Kayla', 'Lagoon', 'Tahiti'] as $i => $name) {
            $this->product((string) (771 + $i), $name.' sandal', 'Sandal.', [], ['price' => (string) (90 + $i).'.00', 'in_stock' => true, 'on_sale' => true]);
            $shown[] = (string) (771 + $i);
        }
        $writer = fn (): array => [
            ['found' => true, 'answer' => 'Sale items can be exchanged within 30 days, not refunded.', 'refs' => [], 'picks' => [], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true],
            ['decision' => 'approve', 'issues' => [], 'revised' => '', 'picks' => [], 'supported' => true, 'on_topic' => true, 'picks_fit' => true, 'safe' => true, 'injection' => false],
        ];

        // after a question about returns, the writer reads it with the conversation
        $this->replies = $writer();
        $thread = [['q' => 'Can I return or exchange it?', 'a' => 'Yes, within 30 days, unworn and in its box.']];
        $out = $this->inShop(fn () => app(AnswerSiteQuestion::class)->replay($this->shop->id, 'what about sale items?', $shown, null, 'en', null, $thread));
        $this->assertNotSame([], $this->calls, 'the writer reads it with the conversation');
        $this->assertStringNotContainsString('on sale now', (string) ($out['result']['answer'] ?? ''));
    }

    public function test_an_answer_left_with_no_words_is_no_answer_though_it_keeps_a_card(): void
    {
        // Rocks, 17.11: "מחפשת מתנה לאמא עד 1,500 ש"ח" was the gift card's card and no words, kept and served again
        $this->product('992', 'כרטיס מתנה', 'כרטיס מתנה למימוש באתר.', [], ['price' => '250.00', 'in_stock' => true]);
        $this->replies = [
            // its one sentence names a price no page has: code takes it out, and the card stays
            ['found' => true, 'answer' => 'כרטיס המתנה עולה 1,234 ש"ח.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'מתנה']], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true],
            ['decision' => 'approve', 'issues' => [], 'revised' => '', 'picks' => [['ref' => 'p1', 'fits' => true, 'why' => '']], 'supported' => true, 'on_topic' => true, 'picks_fit' => true, 'safe' => true, 'injection' => false],
        ];
        $out = $this->inShop(fn () => app(AnswerSiteQuestion::class)->replay($this->shop->id, 'מחפשת מתנה לאמא', ['992']))['result'];

        $this->assertNotSame('answered', $out['outcome'] ?? null, 'a card with nothing to read is no answer');
    }

    public function test_the_v29_checker_reads_the_conversation_and_the_released_one_does_not(): void
    {
        // a reply asked again in other words (Miss Nori, 16.11): the checker of v29 is given the conversation, to see what
        // the answer must not say or offer again; the released checker is given what it was always given
        $this->product('991', 'חולצת כותנה', 'חולצה.', [], ['price' => '100.00', 'in_stock' => true]);
        $thread = [['q' => 'מה לעשות כדי להחזיר את המוצר?', 'a' => 'אפשר להחזיר בחנות או בשליח. תרצו שנפרט מה צריך להכין?']];
        $reply = fn (): array => [
            ['found' => true, 'answer' => 'את החולצה שולחים לחנות שלנו.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'החולצה שנשאלה']], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true],
            ['decision' => 'approve', 'issues' => [], 'revised' => '', 'picks' => [['ref' => 'p1', 'fits' => true, 'why' => '']], 'supported' => true, 'on_topic' => true, 'picks_fit' => true, 'safe' => true, 'injection' => false],
        ];
        $checkerSaw = fn (): array => json_decode((string) collect($this->calls)->last()['user'], true);

        $this->replies = $reply();
        $this->inShop(fn () => app(AnswerSiteQuestion::class)->replay($this->shop->id, 'לאן שולחים?', ['991'], 29, 'he', null, $thread, ['scope_site' => 29, 'verify_site' => 29]));
        $this->assertSame('מה לעשות כדי להחזיר את המוצר?', $checkerSaw()['conversation'][0]['question'] ?? null);

        $this->calls = [];
        $this->replies = $reply();
        $this->inShop(fn () => app(AnswerSiteQuestion::class)->replay($this->shop->id, 'לאן שולחים?', ['991'], 26, 'he', null, $thread, ['verify_site' => 19]));
        $this->assertArrayNotHasKey('conversation', $checkerSaw(), 'the checker before v29 as it was');
    }

    public function test_a_passage_cut_in_a_word_and_a_budget_asked_on_a_product_page(): void
    {
        // the comparison with Zipchat, 16.11: "רוקס אנד ג" copied as the store's name; a gift for mom answered with the ring
        $this->assertSame('הכתובת: רחוב שבזי 31, רוקס אנד', AnswerSiteQuestion::wholeWordsAtEnd('הכתובת: רחוב שבזי 31, רוקס אנד ג'));
        $this->assertSame('ניתן להחזיר תוך 14 יום.', AnswerSiteQuestion::wholeWordsAtEnd('ניתן להחזיר תוך 14 יום.'));
        $this->assertSame('רחוב שבזי 31', AnswerSiteQuestion::wholeWordsAtEnd('רחוב שבזי 31'));
        $this->assertSame('זהב צהוב וזהב לבן', AnswerSiteQuestion::wholeWordsAtEnd('זהב צהוב וזהב לבן'));

        $this->assertTrue(AnswerSiteQuestion::asksBeyondThePage('מתנה לאמא עד 1,500 ש"ח'));
        $this->assertTrue(AnswerSiteQuestion::asksBeyondThePage('עגילי זהב לבן במלאי עד 2,000'));
        $this->assertFalse(AnswerSiteQuestion::asksBeyondThePage('יש את הטבעת הזו במידה 52?'), 'about the piece on the page');
        $this->assertFalse(AnswerSiteQuestion::asksBeyondThePage('יש משהו דומה לזה עד 1,500?'), 'about this one');
        $this->assertFalse(AnswerSiteQuestion::asksBeyondThePage('מה מדיניות ההחזרות?'), 'no budget');
    }

    public function test_a_kept_answer_that_rests_on_product_pages_shows_those_products_as_its_cards(): void
    {
        // QA, 16.11: "שמלה שחורה לחתונה עד 200" recommended two dresses by name, citing their pages, and showed no card of
        // them: the storefront filled the place with other dresses
        $this->product('991', 'שמלת מקסי דיאנה | שחור', 'שמלה.', [], ['price' => '189.00', 'in_stock' => true]);
        $this->product('992', 'שמלת מיני אלישיה | שחור', 'שמלה.', [], ['price' => '149.00', 'in_stock' => true]);
        $urls = ['991' => 'https://store.test/p/diana', '992' => 'https://store.test/p/alicia'];
        $this->inShop(fn () => collect($urls)->each(fn (string $url, string $id) => CatalogProduct::query()->where('external_id', $id)->update(['url' => $url])));
        $this->travel(1)->minutes();
        $question = 'איזה בורג מתאים לדק?';
        $this->replies = [
            ['found' => true, 'answer' => 'לדק כדאי בורג נירוסטה.', 'refs' => ['s2'], 'picks' => [['ref' => 'p1', 'why' => 'מתאים לדק']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->assertSame('model', $this->ask($question)->json('data.from'));
        // kept, as such an answer is: no pick of its own, its products cited as its sources
        $this->inShop(fn () => AssistantAnswer::query()->where('question_key', Question::key($question))->update(['picks' => json_encode([]),
            'sources' => json_encode([['title' => 'שמלת מיני אלישיה | שחור', 'url' => $urls['992'], 'type' => 'product'], ['title' => 'שמלת מקסי דיאנה | שחור', 'url' => $urls['991'], 'type' => 'product']])]));

        $data = $this->ask($question)->json('data');

        $this->assertSame('bank', $data['from']);
        $this->assertSame(['992', '991'], array_column($data['picks'], 'external_id'), 'the products it rests on, in the order cited');
    }

    public function test_an_answer_kept_in_another_language_than_the_question_is_not_served(): void
    {
        $this->inShop(fn () => AssistantAnswer::query()->create(['shop_id' => $this->shop->id, 'question' => 'What is your return policy?', 'question_key' => Question::key('What is your return policy?'),
            'answer' => 'אפשר להחזיר תוך 14 יום.', 'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN, 'prompt_version' => AnswerSiteQuestion::PROMPT_VERSION,
            'asked_count' => 9, 'shared_at' => now(), 'last_asked_at' => now()]));
        $this->replies = [
            ['found' => true, 'answer' => 'You can return it in its original packaging.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];

        $data = $this->askIn('What is your return policy?', 'en')->assertOk()->json('data');

        $this->assertNotSame('bank', $data['from'] ?? null, 'the Hebrew answer kept for it is not the answer');
        $this->assertStringStartsWith('You can return it', (string) $data['answer']);
    }

    private function askIn(string $question, ?string $locale): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['question' => $question, 'vid' => self::VID] + ($locale === null ? [] : ['locale' => $locale]), JSON_UNESCAPED_UNICODE));
    }

    private function ask(string $question): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['question' => $question, 'vid' => self::VID, 'locale' => 'he'], JSON_UNESCAPED_UNICODE));
    }

    public function test_the_cheapest_is_arithmetic_code_answers_it_and_no_model_is_asked(): void
    {
        $this->drills();

        $data = $this->askWith('איזו מברגה הכי זולה?', ['701', '704', '702', '703'])->json('data');

        $this->assertSame(['answered', 'code'], [$data['outcome'], $data['from']]);
        $this->assertSame(['702', '701'], array_column($data['picks'], 'external_id'), 'cheapest in stock first; the sold-out one and the bit are not drills on offer');
        $this->assertStringContainsString('מברגה איינהל', $data['answer']);
        $this->assertSame([], $this->calls, 'no model for arithmetic');
    }

    public function test_a_price_range_is_arithmetic_too_and_only_what_the_question_names_is_picked(): void
    {
        $this->product('801', 'עגילים צמודים כדורי זהב חלולים', 'עגילים.', [], ['price' => '450.00', 'in_stock' => true]);
        $this->product('802', 'עגילי חישוק זהב 14K', 'עגילים.', [], ['price' => '480.00', 'in_stock' => true]);
        $this->product('803', 'תליון מפתח עדין', 'תליון.', [], ['price' => '350.00', 'in_stock' => true]);
        $this->product('804', 'עגילי יהלום', 'עגילים.', [], ['price' => '900.00', 'in_stock' => true]);
        $this->product('805', 'חישוקי זהב 14K', 'חישוקים.', [], ['price' => '300.00', 'in_stock' => true]);
        // The search reads the words (see SearchFlowTest): the hoops are in the earrings category.
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return array_values(array_intersect($externalIds, $this->all($shopId, $question)));
            }

            public function holds(string $shopId, string $words): bool
            {
                return $this->all($shopId, $words) !== [];
            }

            public function namesNothing(string $shopId, string $words): bool
            {
                return ! $this->holds($shopId, $words);
            }

            public function all(string $shopId, string $question): array
            {
                return str_contains($question, 'עגילים') ? ['801', '802', '804', '805'] : [];
            }
        });

        $data = $this->askWith('עגילים עד 500 ש"ח?', ['803', '802', '801', '804', '805'])->json('data');

        $this->assertSame(['answered', 'code'], [$data['outcome'], $data['from']]);
        $this->assertSame(['805', '801', '802'], array_column($data['picks'], 'external_id'), 'earrings up to 500 as the search reads them, cheapest first: the hoops whose title never says earrings, not the pendant, not the 900');
        $this->assertStringContainsString('חישוקי זהב', $data['answer']);
        $this->assertSame([], $this->calls, 'no model for arithmetic');

        // Nothing it names in the range: a model reads it, as before.
        $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => []]];
        $this->assertSame('model', $this->askWith('צמיד עד 500', ['803', '801'])->json('data.from'));

        // More in the range than three: every one, cheapest first, as a set the drawer shows whole (Naot QA, 16.11).
        $this->product('806', 'עגילי טיפה זהב', 'עגילים.', [], ['price' => '420.00', 'in_stock' => true]);
        $this->product('807', 'עגילי פרח זהב', 'עגילים.', [], ['price' => '390.00', 'in_stock' => true]);
        $this->product('808', 'עגילי לב שאזלו', 'עגילים.', [], ['price' => '200.00', 'in_stock' => false]);
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return array_values(array_intersect($externalIds, $this->all($shopId, $question)));
            }

            public function holds(string $shopId, string $words): bool
            {
                return $this->all($shopId, $words) !== [];
            }

            public function namesNothing(string $shopId, string $words): bool
            {
                return ! $this->holds($shopId, $words);
            }

            public function all(string $shopId, string $question): array
            {
                return ['801', '802', '804', '805', '806', '807', '808'];
            }
        });
        $data = $this->askWith('עגילים עד 500 ש"ח?', ['801'])->json('data');
        $this->assertSame(['answered', 'code', []], [$data['outcome'], $data['from'], $data['picks']]);
        $this->assertSame(['805', '807', '806', '801', '802'], $data['set']['ids'], 'every earring up to 500 in stock, cheapest first');
        $this->assertStringStartsWith('מצאנו 5 מוצרים', $data['answer']);

        // ...and where the writer answers, its words are said over that set (Aviad, 20.11: "found 40 products" alone, no
        // answer written): the set is code's, every one in the price, the words the writer's
        // (the candidate picker first, keeping all; then the writer and its checker)
        $this->replies = [[], ['found' => true, 'answer' => 'יש לנו כמה עגילי זהב יפים בתקציב הזה, למשל עגילי פרח.', 'refs' => ['p2'], 'picks' => [['ref' => 'p2', 'why' => 'בתקציב']]], ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true]];
        $data = $this->askWith('אילו עגילים יש לכם עד 500 ש"ח?', ['801'])->json('data');
        $this->assertSame(['answered', 'model'], [$data['outcome'], $data['from']]);
        $this->assertSame('יש לנו כמה עגילי זהב יפים בתקציב הזה, למשל עגילי פרח.', $data['answer']);
        $this->assertSame(['805', '807', '806', '801', '802'], $data['set']['ids'], 'the set is still every earring up to 500 in stock');

        // "cheaper than" is the price, not "the cheapest": every one in the range too; "the cheapest" stays three picks
        $this->assertSame(['805', '807', '806', '801', '802'], $this->askWith('any earrings cheaper than 500 dollars?', ['801'])->json('data.set.ids'));
        $this->assertSame(['805', '807', '806'], array_column($this->askWith('the cheapest earrings under 500 dollars', ['801'])->json('data.picks'), 'external_id'));

        // A wish with a price is no product search: no product holds "מתנה לאשתי ליום נישואין, משהו עדין", and the writer
        // chooses among what is within the price, never code's "the cheapest" (Rocks QA, 16.11: one ring, "the cheapest")
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return array_values(array_intersect($externalIds, $this->all($shopId, $question)));
            }

            public function holds(string $shopId, string $words): bool
            {
                return str_contains($words, 'עגילים');
            }

            public function namesNothing(string $shopId, string $words): bool
            {
                return ! $this->holds($shopId, $words);
            }

            public function all(string $shopId, string $question): array
            {
                return ['801', '802', '804', '805', '806', '807'];
            }
        });
        $this->replies = [
            ['found' => true, 'answer' => 'למתנה עדינה, עגילי פרח זהב או עגילי טיפה זהב.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'עדינים']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->assertSame('model', $this->askWith('מתנה לאשתי ליום נישואין, משהו עדין עד 2000 שקל', ['806', '807'])->json('data.from'));
        // and a product search with a price is still code's
        $this->assertSame('code', $this->askWith('עגילים עד 500 ש"ח?', ['801'])->json('data.from'));

        // words one product happens to say are no kind of product: "מתנה לאמא" is the text of one ring ("MOM"), and code
        // answered that ring alone, no choice, no question back (Rocks, 17.11)
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return array_values(array_intersect($externalIds, $this->all($shopId, $question)));
            }

            public function holds(string $shopId, string $words): bool
            {
                return true;
            }

            public function namesNothing(string $shopId, string $words): bool
            {
                return false;
            }

            public function all(string $shopId, string $question): array
            {
                return str_contains($question, 'אמא') ? ['806'] : ['801', '802', '804', '805', '806', '807'];
            }
        });
        $this->replies = [
            ['found' => true, 'answer' => 'למתנה לאמא, עגילי פרח זהב או עגילי טיפה זהב.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'עדינים']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->assertSame('model', $this->askWith('מחפשת מתנה לאמא עד 1,500 ש"ח', ['806'])->json('data.from'));
    }

    public function test_a_reply_naming_a_kind_inside_the_conversations_price_is_every_one_of_it_never_called_the_cheapest(): void
    {
        // Rocks QA, 16.11: "מתנה לאישתי עד 2000 ש"ח?", then "שרשרת עדינה": one necklace of the answer before, "במחיר שביקשת,
        // הזול ביותר שבמלאי", though no one asked for the cheapest and the store has many under 2000
        foreach (['981' => 1600, '982' => 900, '983' => 1200, '984' => 1900, '985' => 2600] as $id => $price) {
            $this->product((string) $id, 'שרשרת עדינה '.$id, 'שרשרת.', [], ['price' => $price.'.00', 'in_stock' => true]);
        }
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return array_values(array_intersect($externalIds, $this->all($shopId, $question)));
            }

            public function holds(string $shopId, string $words): bool
            {
                return str_contains($words, 'שרשרת');
            }

            public function namesNothing(string $shopId, string $words): bool
            {
                return ! $this->holds($shopId, $words);
            }

            public function all(string $shopId, string $question): array
            {
                return str_contains($question, 'שרשרת') ? ['981', '982', '983', '984', '985'] : [];
            }
        });
        $thread = [['q' => 'מתנה לאישתי עד 2000 ש"ח?', 'a' => 'עגילים הם בחירה נוחה במיוחד.']];

        $data = $this->reply('שרשרת עדינה', $thread, ['981'])->json('data');

        $this->assertSame('code', $data['from']);
        $ids = $data['set']['ids'] ?? array_column($data['picks'], 'external_id');
        $this->assertEqualsCanonicalizing(['981', '982', '983', '984'], $ids, 'every necklace in the store under the conversation\'s 2000, not the one shown before');
        $this->assertStringNotContainsString('הזול', $data['answer'], 'no one asked for the cheapest');
    }

    public function test_a_reply_inside_the_conversations_price_keeps_its_wish_where_the_stores_products_say_it(): void
    {
        // Rocks, 18.11: "מה מתאים למתנה לגבר עד 2000 ש״ח?", then "איזה טבעות יש?": "228 products in your price", women's
        // rings and a ring sizer among them, the man forgotten.
        foreach (['971' => ['טבעת לגבר חותם', 1500], '972' => ['טבעת לגבר זהב', 1900], '973' => ['טבעת סוליטר', 1200], '974' => ['טבעת אירוסין', 900], '975' => ['טבעת לגבר כבדה', 2600], '976' => ['מדיד טבעות', 5]] as $id => [$title, $price]) {
            $this->product((string) $id, $title, 'טבעת.', [], ['price' => $price.'.00', 'in_stock' => true]);
        }
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return array_values(array_intersect($externalIds, $this->all($shopId, $question)));
            }

            public function holds(string $shopId, string $words): bool
            {
                return str_contains($words, 'טבע') || str_contains($words, 'לגבר');
            }

            public function namesNothing(string $shopId, string $words): bool
            {
                return ! $this->holds($shopId, $words);
            }

            public function all(string $shopId, string $question): array
            {
                if (! str_contains($question, 'טבע')) {
                    return [];
                }

                return str_contains($question, 'לגבר') ? ['971', '972', '975'] : ['971', '972', '973', '974', '975', '976'];
            }
        });
        $thread = [['q' => 'מה מתאים למתנה לגבר עד 2000 ש״ח?', 'a' => 'כרטיס מתנה יכול להיות פתרון נפלא. יש לכם העדפה?']];

        $data = $this->reply('איזה טבעות יש?', $thread)->json('data');

        $this->assertSame('code', $data['from']);
        $ids = $data['set']['ids'] ?? array_column($data['picks'], 'external_id');
        $this->assertEqualsCanonicalizing(['971', '972'], $ids, 'the men\'s rings in the conversation\'s 2000: never the women\'s, the ring sizer or the one over the price');
    }

    public function test_a_wish_with_a_price_that_names_no_product_is_chosen_from_every_kind_inside_it_a_gift_card_last(): void
    {
        // Rocks QA, 16.11: "אני רוצה לקנות לחברה שלי מתנה ליום הולדת, תקציב עד 1500" was answered with the gift card at 250,
        // the one product whose title says "מתנה"
        $typed = fn (string $type, string $price): array => ['price' => $price, 'in_stock' => true, 'payload' => ['title' => '', 'description' => '', 'categories' => [], 'attributes' => [], 'meta' => [], 'product_type' => $type]];
        $this->product('971', 'כרטיס מתנה - Gift Card', 'כרטיס.', [], $typed('', '250.00'));
        $this->product('972', 'טבעת זהב עדינה', 'טבעת.', [], $typed('טבעות', '1200.00'));
        $this->product('973', 'טבעת יהלום קטנה', 'טבעת.', [], $typed('טבעות', '1400.00'));
        $this->product('974', 'שרשרת זהב צהוב', 'שרשרת.', [], $typed('שרשראות', '900.00'));
        $this->product('975', 'עגילי פנינה', 'עגילים.', [], $typed('עגילים', '650.00'));
        $this->product('976', 'צמיד טניס יהלומים', 'צמיד.', [], $typed('צמידים', '4800.00'));
        $this->product('977', 'טבעת שאזלה', 'טבעת.', [], ['in_stock' => false] + $typed('טבעות', '1000.00'));
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return array_values(array_intersect($externalIds, $this->all($shopId, $question)));
            }

            public function holds(string $shopId, string $words): bool
            {
                return false;
            }

            public function namesNothing(string $shopId, string $words): bool
            {
                return ! str_contains($words, 'טבעת');
            }

            public function all(string $shopId, string $question): array
            {
                return str_contains($question, 'מתנה') ? ['971'] : [];
            }
        });
        $this->replies = [
            ['found' => true, 'answer' => 'ליום הולדת, טבעת זהב עדינה או עגילי פנינה.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'עדינה'], ['ref' => 'p3', 'why' => 'קלאסיים']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('אני רוצה לקנות לחברה שלי מתנה ליום הולדת, תקציב עד 1500', ['971'])->json('data');

        $this->assertSame('model', $data['from']);
        $sent = array_column(json_decode($this->calls[0]['user'], true)['products'], 'title');
        $this->assertSame('כרטיס מתנה - Gift Card', end($sent), 'the gift card is still offered, after what can be given itself');
        $this->assertEqualsCanonicalizing(['טבעת', 'שרשרת', 'עגילי'], array_map(fn (string $t): string => explode(' ', $t)[0], array_slice($sent, 0, 3)), 'one of each kind first, not two rings before the necklace');
        $this->assertNotContains('צמיד טניס יהלומים', $sent, 'nothing over the budget');
        $this->assertNotContains('טבעת שאזלה', $sent, 'nothing out of stock');
        // under the answer: more of the range inside the budget than the writer picked, and its kinds to choose by, each a
        // search inside the price (as Aviad asked: "earrings, rings or necklaces?", a tap shows them)
        $picked = array_column($data['picks'], 'external_id');
        $this->assertTrue($data['named']['budget']);
        $this->assertNotEmpty($data['named']['ids']);
        $this->assertSame([], array_intersect($picked, $data['named']['ids']), 'what was picked is not shown twice');
        $this->assertNotContains('971', $data['named']['ids'], 'no gift card among the range');
        $this->assertEqualsCanonicalizing(['טבעות עד 1,500', 'שרשראות עד 1,500', 'עגילים עד 1,500'], array_column($data['related'], 'name'));
        // a kind is offered by a shopper's word: never a store's inner code, never in another language (Miss Nori, 16.11: "PANTS עד 250")
        $readable = new \ReflectionMethod(AnswerSiteQuestion::class, 'readableKind');
        foreach ([['SUIT-PANTS', 'מתנה עד 250', false], ['SHIRT', 'מתנה עד 250', false], ['Rings', 'מתנה עד 250', false], ['טבעות', 'מתנה עד 250', true], ['Rings', 'a gift under 250', true], ['Gift Sets', 'a gift under 250', true], ['T-Shirts', 'a gift under 250', true], ['NEWYEAR26', 'שמלה שחורה לחתונה', false], ['KIDSDRESS', 'שמלה שחורה לחתונה', false], ['01/07/2025', 'שמלה שחורה לחתונה', false], ['kids_dress', 'a dress', false], ['שמלות ערב', 'שמלה שחורה לחתונה', true]] as [$name, $question, $ok]) {
            $this->assertSame($ok, $readable->invoke(null, $name, $question), $name);
        }

        // a wish that names a kind ("טבעת עדינה ליום הולדת עד 1500") is chosen among what its words name, as before
        $this->calls = [];
        $this->replies = [
            ['found' => true, 'answer' => 'טבעת זהב עדינה.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'עדינה']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->askWith('טבעת עדינה ליום הולדת לחברה שלי עד 1500', ['972']);
        $this->assertNotContains('שרשרת זהב צהוב', array_column(json_decode($this->calls[0]['user'], true)['products'], 'title'), 'no other kinds added to a named one');
    }

    public function test_a_pick_whose_reason_is_marks_alone_shows_no_reason(): void
    {
        // Naot QA, 16.11: a card under "What size should I get if I am a US 8?" said only '""' under its name.
        $this->drills();
        $this->replies = [
            ['found' => true, 'answer' => 'הראשונה חזקה יותר.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => '""'], ['ref' => 'p2', 'why' => 'קלה יותר']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $picks = $this->askWith('מה ההבדל ביניהם?', ['701', '702'])->json('data.picks');

        $this->assertSame([['701', ''], ['702', 'קלה יותר']], array_map(fn (array $p): array => [$p['external_id'], $p['why']], $picks), 'the card stays, without the marks');
    }

    public function test_a_question_about_the_products_in_front_of_the_shopper_is_never_kept_for_the_next_one(): void
    {
        // The drawer's "not sure" chips ("מה ההבדל ביניהם?") are the same words for every shopper; their products are not.
        $this->drills();
        $this->replies = [
            ['found' => true, 'answer' => 'הראשונה חזקה יותר.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'חזקה יותר']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $this->askWith('מה ההבדל ביניהם?', ['701', '702']);

        $this->assertSame(0, $this->inShop(fn () => AnswerSiteQuestion::siteAnswers()->count()), 'not kept: the next shopper has other products in front of them');
        $this->assertTrue(AnswerSiteQuestion::pointsBack('איזה מהם הכי משתלם?'));
        $this->assertTrue(AnswerSiteQuestion::pointsBack('מה הכי מתאים לי מביניהם?'));
        $this->assertFalse(AnswerSiteQuestion::pointsBack('מה מדיניות ההחזרות?'));
    }

    public function test_the_cheapest_of_what_the_question_names_is_chosen_from_the_whole_store_not_what_was_shown(): void
    {
        // Seen on Rocks (24.10): "איזו טבעת עם יהלום הכי זולה" with two products on screen; the model named the cheaper
        // of the two, or said the site does not tell. The store has cheaper diamond rings than both.
        $this->product('951', 'טבעת זהב ויהלומים 18K', 'טבעת.', [], ['price' => '6900.00', 'in_stock' => true]);
        $this->product('952', 'טבעת סוליטר משובצת יהלום', 'טבעת.', [], ['price' => '4200.00', 'in_stock' => true]);
        $this->product('953', 'טבעת וינטג׳ עדינה משובצת יהלומים, זהב 10K', 'טבעת.', [], ['price' => '890.00', 'in_stock' => true]);
        $this->product('954', 'טבעת וינטאג משובצת יהלום, זהב 10K', 'טבעת.', [], ['price' => '1150.00', 'in_stock' => true]);
        $this->product('955', 'טבעת יהלום שאזלה', 'טבעת.', [], ['price' => '600.00', 'in_stock' => false]);
        $this->product('956', 'תליון משובץ יהלום', 'תליון.', [], ['price' => '700.00', 'in_stock' => true]);
        // as the store's search reads the words: the diamond rings, whatever the shopper had on screen
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return array_values(array_intersect($externalIds, $this->all($shopId, $question)));
            }

            public function holds(string $shopId, string $words): bool
            {
                return $this->all($shopId, $words) !== [];
            }

            public function namesNothing(string $shopId, string $words): bool
            {
                return ! $this->holds($shopId, $words);
            }

            public function all(string $shopId, string $question): array
            {
                return str_contains($question, 'טבעת') ? ['951', '952', '953', '954', '955'] : [];
            }
        });

        $data = $this->askWith('איזו טבעת עם יהלום הכי זולה שיש לכם?', ['951', '952'])->json('data');

        $this->assertSame(['answered', 'code'], [$data['outcome'], $data['from']]);
        $this->assertSame('953', $data['picks'][0]['external_id'], 'the cheapest diamond ring in stock in the store, not of the two shown; not the sold-out one, not the pendant');
        $this->assertStringContainsString('טבעת וינטג׳ עדינה', $data['answer']);
        $this->assertSame([], $this->calls, 'no model for arithmetic');
    }

    public function test_a_price_range_with_another_wish_is_the_writers_among_the_stores_products_inside_it(): void
    {
        // Rocks, 25.10: "the biggest diamond between 2000 and 2500" read only "up to 2500", the search found nothing, the
        // writer got two rings at 1,000 and told the shopper the site shows no prices.
        $this->product('961', 'טבעת סוליטר משובצת יהלום במשקל 0.93 קארט', 'טבעת.', [], ['price' => '2500.00', 'in_stock' => true]);
        $this->product('962', 'טבעת וינטאג משובצת יהלום מרכזי 0.18 קראט', 'טבעת.', [], ['price' => '2050.00', 'in_stock' => true]);
        $this->product('963', 'טבעת זהב משובצת יהלום', 'טבעת.', [], ['price' => '1000.00', 'in_stock' => true]);
        $this->product('964', 'טבעת סוליטר יהלום גדולה', 'טבעת.', [], ['price' => '6900.00', 'in_stock' => true]);
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return array_values(array_intersect($externalIds, $this->all($shopId, $question)));
            }

            public function holds(string $shopId, string $words): bool
            {
                return $this->all($shopId, $words) !== [];
            }

            public function namesNothing(string $shopId, string $words): bool
            {
                return ! $this->holds($shopId, $words);
            }

            public function all(string $shopId, string $question): array
            {
                return str_contains($question, 'טבעת') ? ['963', '964', '961', '962'] : [];
            }
        });
        $this->replies = [
            ['found' => true, 'answer' => 'הטבעת עם היהלום הגדול ביותר בטווח היא זו של 0.93 קארט.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'יהלום של 0.93 קארט']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('תראה לי טבעת יהלום עם היהלום הכי גדול במחיר שבין 2000 עד 2500', ['963'])->json('data');

        $sent = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['טבעת סוליטר משובצת יהלום במשקל 0.93 קארט', 'טבעת וינטאג משובצת יהלום מרכזי 0.18 קראט'], array_column($sent['products'], 'title'), 'the store\'s diamond rings inside the range, not the one at 1,000 on screen, not the one at 6,900');
        $this->assertSame(['answered', 'model', '961'], [$data['outcome'], $data['from'], $data['picks'][0]['external_id']], 'the biggest diamond is the writer\'s to choose, not the cheapest by code');
        $this->assertEquals(['min' => 2000, 'max' => 2500], $data['range'], 'the storefront keeps its suggestions to the range');
        $this->assertNull(AnswerSiteQuestion::notFoundLine('האתר לא מציג כאן מחירים, לכן אי אפשר לסנן לפי טווח.', 'טבעת עד 2500'), 'the writer never sees prices: nothing it says of them is shown');
    }

    public function test_the_cheapest_with_no_product_named_is_the_whole_stores_merchandise(): void
    {
        // What the words happened to find: a ring and a pendant, far from the cheapest.
        $typed = fn (string $type): array => ['payload' => ['title' => '', 'description' => '', 'categories' => [], 'attributes' => [], 'meta' => [], 'product_type' => $type]];
        $this->product('901', 'טבעת יהלום', 'טבעת.', [], ['price' => '4900.00'] + $typed('טבעות'));
        $this->product('902', 'תליון לב משובץ', 'תליון.', [], ['price' => '2200.00'] + $typed('תליונים'));
        $this->product('903', 'תליון מפתח עדין', 'תליון.', [], ['price' => '350.00'] + $typed('תליונים'));
        $this->product('904', 'עגילים צמודים כדורי זהב', 'עגילים.', [], ['price' => '450.00'] + $typed('עגילים'));
        $this->product('905', 'עגילים שאזלו', 'עגילים.', [], ['price' => '100.00', 'in_stock' => false] + $typed('עגילים'));
        // Not merchandise: a ring sizer with no kind, a gift card.
        $this->product('906', 'מדיד טבעות', 'מדיד.', [], ['price' => '5.00']);
        $this->product('907', 'כרטיס מתנה - Gift Card', 'שובר.', [], ['price' => '100.00'] + $typed('כרטיס מתנה'));

        foreach (['מה הכי זול בין המוצרים?', 'מה הכי זול באתר', 'יש לכם משהו זול?'] as $question) {
            $data = $this->askWith($question, ['901', '902'])->json('data');

            $this->assertSame(['answered', 'code'], [$data['outcome'], $data['from']], $question);
            $this->assertSame(['903', '904', '902'], array_column($data['picks'], 'external_id'), $question.': the store\'s cheapest in stock');
            $this->assertStringContainsString('באתר', $data['answer']);
        }

        $this->assertSame(['901', '902', '904'], array_column($this->askWith('מה הכי יקר שיש לכם?', [])->json('data.picks'), 'external_id'), 'and the dearest, even when the words found nothing');
        // Rocks, 8.11: "show me" is not a product, nor "the product" joined to the word before it; and after a search,
        // "that you have" still means the whole store
        foreach (['תראה לי מהמוצר הכי יקר שיש לכם', 'תראה לי מה המוצר הכי יקר שיש לכם', 'show me the most expensive product you have'] as $question) {
            $this->assertSame(['901', '902', '904'], array_column($this->askWith($question, ['902', '903'], ['902', '903'])->json('data.picks'), 'external_id'), $question);
        }
        $this->assertSame([], $this->calls, 'no model for arithmetic');

        // A product named still means that product, of what was found.
        $this->assertSame(['902'], array_column($this->askWith('איזה תליון הכי זול?', ['901', '902'])->json('data.picks'), 'external_id'));
    }

    public function test_a_question_that_names_nothing_is_about_what_the_conversation_just_showed(): void
    {
        $typed = fn (string $type): array => ['payload' => ['title' => '', 'description' => '', 'categories' => [], 'attributes' => [], 'meta' => [], 'product_type' => $type]];
        $this->product('901', 'טבעת יהלום', 'טבעת.', [], ['price' => '4900.00'] + $typed('טבעות'));
        $this->product('902', 'תליון לב משובץ', 'תליון.', [], ['price' => '2200.00'] + $typed('תליונים'));
        $this->product('903', 'תליון מפתח עדין', 'תליון.', [], ['price' => '350.00'] + $typed('תליונים'));
        $this->product('904', 'עגילים צמודים כדורי זהב', 'עגילים.', [], ['price' => '450.00'] + $typed('עגילים'));
        // The drawer's turn before showed these three; the words of the question found the ring alone.
        $before = ['901', '902', '904'];

        foreach (['מה הכי זול?', 'הכי זול', 'איזה מהם הכי זול?'] as $question) {
            $data = $this->askWith($question, ['901'], $before)->json('data');

            $this->assertSame(['answered', 'code', 'previous'], [$data['outcome'], $data['from'], $data['context'] ?? null], $question);
            $this->assertSame(['904', '902', '901'], array_column($data['picks'], 'external_id'), $question.': the cheapest of what was shown');
            $this->assertStringNotContainsString('באתר', $data['answer']);
        }

        $this->assertSame(['901', '902', '904'], array_column($this->askWith('ומה הכי יקר?', [], $before)->json('data.picks'), 'external_id'));

        // The whole store, when the shopper says so; and with no turn before, as always.
        $store = $this->askWith('מה הכי זול באתר?', [], $before)->json('data');
        $this->assertSame(['903', '904', '902'], array_column($store['picks'], 'external_id'));
        $this->assertArrayNotHasKey('context', $store);
        $this->assertSame(['903', '904', '902'], array_column($this->askWith('מה הכי זול?', [])->json('data.picks'), 'external_id'));

        // A product named is that product, not the turn before.
        $this->assertSame(['903', '902'], array_column($this->askWith('איזה תליון הכי זול?', ['902', '903'], $before)->json('data.picks'), 'external_id'));
        $this->assertSame([], $this->calls, 'no model for arithmetic');
    }

    public function test_a_follow_up_the_model_answers_is_about_those_products_and_is_never_kept(): void
    {
        $this->drills();
        $this->replies = [
            ['found' => true, 'answer' => 'לבית, מברגה איינהל.', 'refs' => [], 'picks' => [['ref' => 'p2', 'why' => 'מתאימה לבית']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('איזה מהם מתאים לבית?', [], ['701', '702'])->json('data');

        $sent = collect($this->calls)->map(fn (array $call): ?array => json_decode($call['user'], true))->first(fn (?array $input): bool => isset($input['products']));
        $this->assertNotNull($sent, 'the writer was asked');
        $this->assertCount(2, $sent['products'], 'the products of the turn before, and only them');
        $this->assertSame('previous', $data['context'] ?? null);
        $this->assertSame(0, $this->inShop(fn () => AssistantAnswer::query()->count()), 'an answer about one conversation is not the next shopper\'s');
    }

    public function test_a_reply_to_an_answer_is_read_with_the_conversation_and_never_kept(): void
    {
        // Mycolivia, 25.10: "אם תרצו, נכוון אתכם לפי המטרה" left the shopper no way to answer it.
        $this->drills();
        $this->replies = [
            ['found' => true, 'answer' => 'לבית, מברגה איינהל.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'מתאימה לבית']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $thread = [['q' => 'איזו מברגה כדאי לי?', 'a' => 'יש לנו כמה. לאיזה שימוש אתם מחפשים?'], ['q' => '', 'a' => 'no question: left out']];
        $data = $this->reply('לבית', $thread)->json('data');

        $sent = collect($this->calls)->map(fn (array $call): ?array => json_decode($call['user'], true))->first(fn (?array $input): bool => isset($input['passages']));
        $this->assertNotNull($sent, 'the writer was asked');
        $this->assertSame('לבית', $sent['question'], 'the shopper\'s own words (v39)');
        $this->assertSame('איזו מברגה כדאי לי? לבית', $sent['read_as'], 'with no topic check to write it alone: searched with the question before it');
        $this->assertSame([['question' => 'איזו מברגה כדאי לי?', 'answer' => 'יש לנו כמה. לאיזה שימוש אתם מחפשים?']], $sent['conversation']);
        $this->assertSame('answered', $data['outcome']);
        $this->assertArrayNotHasKey('corrected', $data, 'a reply is shown as written, never "הבנתי: …"');
        $this->assertSame(0, $this->inShop(fn () => AssistantAnswer::query()->count()), 'a reply\'s words alone mean nothing for the next shopper');
    }

    public function test_a_reply_is_answered_as_it_stands_alone_never_the_question_before_it_again(): void
    {
        // Mycolivia, 25.10: "כמה פעמים כדאי לקחת אותה?" after an answer about cordyceps was read as "איזה מוצר הכי טוב
        // אחרי אימון? כמה פעמים…", and the first question was answered again.
        $this->drills();
        Features::override('assistant.site_scope', true);
        $this->replies = [
            ['about_store' => true, 'harmful' => false, 'injection' => false, 'corrected' => 'כמה פעמים כדאי לקחת אותה?', 'standalone' => 'כמה פעמים כדאי להשתמש במברגה מקיטה?'],
            ['found' => true, 'answer' => 'לפי הדף, טעינה מלאה מספיקה ליום עבודה.', 'refs' => [], 'picks' => []],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $this->reply('כמה פעמים כדאי לקחת אותה?', [['q' => 'איזו מברגה הכי טובה?', 'a' => 'מברגה מקיטה 18V.']], ['701']);

        $inputs = collect($this->calls)->map(fn (array $call): ?array => json_decode($call['user'], true));
        $this->assertSame([['question' => 'איזו מברגה הכי טובה?', 'answer' => 'מברגה מקיטה 18V.']], $inputs[0]['conversation'], 'the topic check reads it with the conversation');
        $this->assertSame('כמה פעמים כדאי לקחת אותה?', $inputs[1]['question'], 'the writer reads the reply as typed, with the conversation (v39)');
        $this->assertSame('כמה פעמים כדאי להשתמש במברגה מקיטה?', $inputs[1]['read_as'], 'and as it stands alone, a help');
        $this->assertCount(1, $inputs[1]['products'], 'about what the answer before showed');
    }

    public function test_a_reply_reaches_the_writer_and_the_check_as_typed_and_the_topic_checks_reading_only_as_a_help(): void
    {
        // Rocks, 18.11: "48 ס"מ" after "אפשר לקצר את השרשרת, לאיזה אורך?" was rewritten by the topic check as a question of
        // its own, and the writer, given that alone, answered about a bracelet's wrist. From answer_site v39 the writer reads
        // the shopper's words with the conversation; the rewrite still searches.
        $this->drills();
        Features::override('assistant.site_scope', true);
        $this->replies = [
            ['about_store' => true, 'harmful' => false, 'injection' => false, 'corrected' => 'יש משהו נטען?', 'standalone' => 'יש מקדחה נטענת לשימוש ביתי?'],
            ['found' => true, 'answer' => 'כן, המברגה של מקיטה נטענת.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'נטענת']]],
            ['decision' => 'approve', 'issues' => [], 'revised' => '', 'picks' => [], 'supported' => true, 'on_topic' => true, 'picks_fit' => true, 'safe' => true, 'injection' => false],
        ];
        $thread = [['q' => 'איזו מקדחה כדאי לי לשימוש ביתי?', 'a' => 'מקדחה/מברגה איינהל.']];

        $this->inShop(fn () => app(AnswerSiteQuestion::class)->replay($this->shop->id, 'יש משהו נטען?', [], 39, 'he', null, $thread, ['verify_site' => 39]));

        $inputs = collect($this->calls)->map(fn (array $call): ?array => json_decode($call['user'], true));
        $writer = $inputs->first(fn (?array $input): bool => isset($input['passages']) && ! isset($input['answer']));
        $this->assertSame('יש משהו נטען?', $writer['question'], 'the shopper\'s own words');
        $this->assertSame('יש מקדחה נטענת לשימוש ביתי?', $writer['read_as'], 'the topic check\'s reading, a help');
        $this->assertSame([['question' => 'איזו מקדחה כדאי לי לשימוש ביתי?', 'answer' => 'מקדחה/מברגה איינהל.']], $writer['conversation']);
        $check = $inputs->first(fn (?array $input): bool => isset($input['answer']));
        $this->assertSame('יש משהו נטען?', $check['question'], 'the check reads the same words, with the conversation');

        // the released writer is given the reading, as before
        $this->calls = [];
        $this->replies = [
            ['about_store' => true, 'harmful' => false, 'injection' => false, 'corrected' => 'יש משהו נטען?', 'standalone' => 'יש מקדחה נטענת לשימוש ביתי?'],
            ['found' => true, 'answer' => 'כן, המברגה של מקיטה נטענת.', 'refs' => [], 'picks' => []],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->inShop(fn () => app(AnswerSiteQuestion::class)->replay($this->shop->id, 'יש משהו נטען?', [], 35, 'he', null, $thread, ['verify_site' => 37]));
        $writer = collect($this->calls)->map(fn (array $call): ?array => json_decode($call['user'], true))->first(fn (?array $input): bool => isset($input['passages']) && ! isset($input['answer']));
        $this->assertSame('יש מקדחה נטענת לשימוש ביתי?', $writer['question']);
        $this->assertArrayNotHasKey('read_as', $writer);
    }

    public function test_a_new_question_the_topic_check_left_as_it_is_is_never_glued_to_the_one_before(): void
    {
        // Gueta, 18.11: "מה מתאים לקידוח בבטון?" after "איזה עץ הכי טוב לבנית פרגולה?" was searched and answered as both
        // together; the check had left it as it was, and code glued the question before in front of it.
        $this->drills();
        Features::override('assistant.site_scope', true);
        $this->replies = [
            ['about_store' => true, 'harmful' => false, 'injection' => false, 'corrected' => 'מה מתאים לקידוח בבטון?', 'standalone' => ''],
            ['found' => true, 'answer' => 'למקדחה רוטטת, מקדח בטון.', 'refs' => [], 'picks' => []],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $this->reply('מה מתאים לקידוח בבטון?', [['q' => 'איזה עץ הכי טוב לבנית פרגולה?', 'a' => 'עץ אורן דו שכבתי.']]);

        $sent = collect($this->calls)->map(fn (array $call): ?array => json_decode($call['user'], true))->first(fn (?array $input): bool => isset($input['passages']));
        $this->assertSame('מה מתאים לקידוח בבטון?', $sent['question']);
    }

    public function test_a_reply_that_says_nothing_of_price_keeps_the_conversations(): void
    {
        // Rocks, 28.10: "יש משהו עם זהב צהוב?" after "טבעות לנישואים עם יהלום אחד עד 3000 ש"ח" showed a ring of ₪3,300.
        $this->product('971', 'טבעת סוליטר זהב צהוב 14K', 'טבעת.', [], ['price' => '2400.00', 'in_stock' => true]);
        $this->product('972', 'טבעת וינטג׳ משובצת רובי ויהלומים, זהב 18K', 'טבעת.', [], ['price' => '3300.00', 'in_stock' => true]);
        Features::override('assistant.site_scope', true);
        $thread = [['q' => 'איזה טבעות לנישואים עם יהלום אחד יש עד 3000 ש"ח', 'a' => 'יש לנו טבעת סוליטר עדינה בזהב לבן.']];
        $answer = ['found' => true, 'answer' => 'יש לנו טבעת סוליטר בזהב צהוב.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'זהב צהוב']]];
        $checked = ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true];

        // the topic check wrote it alone without the budget: code keeps it
        $this->replies = [['about_store' => true, 'harmful' => false, 'injection' => false, 'corrected' => 'יש משהו עם זהב צהוב?', 'standalone' => 'יש טבעות נישואים עם יהלום אחד בזהב צהוב?'], $answer, $checked];
        $data = $this->reply('יש משהו עם זהב צהוב?', $thread, ['971', '972'])->json('data');

        $sent = json_decode($this->calls[1]['user'], true);
        $this->assertSame(['טבעת סוליטר זהב צהוב 14K'], array_column($sent['products'], 'title'), 'not the ring over the budget the conversation set');
        $this->assertEquals(3000, $data['range']['max'] ?? null);

        // a reply that names its own price keeps to it
        $this->calls = [];
        $this->replies = [['about_store' => true, 'harmful' => false, 'injection' => false, 'corrected' => 'ומה עם עד 4000?', 'standalone' => 'איזה טבעות נישואים עם יהלום אחד יש עד 4000 ש"ח?'], $answer, $checked];
        $this->reply('ומה עם עד 4000?', $thread, ['971', '972']);

        $sent = json_decode($this->calls[1]['user'], true);
        $this->assertCount(2, $sent['products']);
    }

    /**
     * 15.11: "white sneakers" after another search for shoes found three, and was told "this is one for our team to check":
     * the answer's words were most of the answer before. An answer that shows products is never taken for one said again.
     */
    public function test_a_reply_that_shows_products_is_never_taken_for_the_answer_said_again(): void
    {
        $this->drills();
        $before = 'We have cordless drivers from Makita and Einhell, both light, strong and easy to hold for long work.';
        $this->replies = [
            ['found' => true, 'answer' => 'We have cordless drivers from Makita and Einhell, light, strong and easy to hold for long work.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'cordless']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->reply('and the cordless ones?', [['q' => 'which drivers do you have?', 'a' => $before]], ['701', '702'])->assertOk()->json('data');

        $this->assertSame('answered', $data['outcome']);
        $this->assertStringStartsWith('We have cordless drivers', $data['answer'], 'the answer with its products stays');
        $this->assertNotEmpty($data['picks']);
    }

    /** @param  list<array{q: string, a: string}>  $thread */
    private function reply(string $question, array $thread, array $products = []): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: ['HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain'], content: json_encode([
            'question' => $question, 'vid' => self::VID, 'locale' => 'he', 'products' => $products, 'previous' => [], 'thread' => $thread,
        ], JSON_UNESCAPED_UNICODE));
    }

    public function test_what_an_answer_recommends_by_a_name_none_of_its_products_has_brings_the_products_that_hold_it(): void
    {
        // Mycolivia, 25.10: "השיטאקה היא הפטריה שתומכת בבריאות הלב" showed maitake and chaga: shiitake is in the formulas'
        // descriptions only, so none was among the products given, and the cards were a search of the question's words.
        $this->drills();
        $this->app->instance(ProductsDescribed::class, new class implements ProductsDescribed
        {
            public function saying(string $shopId, string $phrase): array
            {
                return $phrase === 'שיטאקה' ? ['702', '703'] : [];
            }
        });
        $this->replies = [
            ['found' => true, 'answer' => 'השיטאקה היא הפטריה שתומכת בבריאות הלב.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'מתאימה']], 'names' => ['שיטאקה', 'ריישי']],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('איזו פטריה טובה ללב?', ['702'])->json('data');

        $this->assertSame('answered', $data['outcome']);
        $this->assertSame(['שיטאקה'], $data['named']['names'], 'a name the answer does not say is no name of it');
        $this->assertSame(array_values(array_diff(['702', '703'], array_column($data['picks'], 'external_id'))), $data['named']['ids'], 'the products that hold it, not those already picked');
    }

    public function test_the_difference_between_the_products_shown_rests_on_the_products_it_names(): void
    {
        // Sela, 25.10: "מה ההבדל ביניהם?" about six books was answered from their descriptions, citing no page, and dropped.
        $this->product('801', 'דרקולה', 'רומן אימה קלאסי על רוזן ערפד בטרנסילבניה.', [], ['price' => '118.00', 'in_stock' => true]);
        $this->product('802', 'מדוע אומות נכשלות', 'ספר כלכלה על מוסדות, עוני ושגשוג של מדינות.', [], ['price' => '84.00', 'in_stock' => true]);
        $this->replies = [
            ['found' => true, 'answer' => '"דרקולה" הוא רומן אימה על רוזן ערפד. "מדוע אומות נכשלות" הוא ספר כלכלה על עוני ושגשוג של מדינות.', 'refs' => [], 'picks' => []],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('מה ההבדל ביניהם?', ['801', '802'])->json('data');

        $this->assertSame('answered', $data['outcome'], 'the books the answer names are what it rests on');
        $this->assertSame(['801', '802'], array_column($data['picks'], 'external_id'));
        $this->assertCount(2, $this->calls, 'and the checker judged them like any pick');
    }

    public function test_a_trial_from_the_search_check_goes_the_whole_way_and_keeps_nothing(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'למטר דק צריך כ-30 ברגים.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];

        $tried = app(AnswerSiteQuestion::class)->trial($this->shop->id, 'כמה ברגים צריך למטר דק?', [], 'he');

        $this->assertSame(['answered', 'model'], [$tried['outcome'], $tried['from']]);
        $this->assertCount(2, $this->calls, 'written and checked, as a shopper\'s');
        $this->assertSame('כמה ברגים צריך למטר דק', $tried['trial']['looks'][0]['pages'][0]['title'] ?? null, 'with what the writer was given');
        $this->assertNotNull($tried['trial']['run_id'] ?? null, 'and its run, paid for and accounted');
        $this->assertSame([0, 0], $this->inShop(fn (): array => [AssistantAnswer::query()->count(), AssistantSearchAsk::query()->count()]), 'no saved answer for the next shopper, no ask in the reports');
    }

    public function test_a_question_from_a_product_page_is_about_that_product_never_a_saved_answer(): void
    {
        $this->drills();
        // Saved before the product was synced: "not ours to answer", for these words, for every product.
        $this->inShop(fn () => AssistantAnswer::query()->create(['shop_id' => $this->shop->id, 'question' => 'מה מיוחד במוצר הזה?', 'question_key' => Question::key('מה מיוחד במוצר הזה?'),
            'answer' => null, 'outcome' => AssistantAnswer::OUT_OF_SCOPE, 'status' => AssistantAnswer::SHOWN, 'prompt_version' => AnswerSiteQuestion::PROMPT_VERSION, 'asked_count' => 3, 'last_asked_at' => now()]));
        $this->replies = [
            ['found' => true, 'answer' => 'מברגה נטענת של מקיטה, 18V.', 'refs' => [], 'picks' => []],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('מה מיוחד במוצר הזה?', ['701'], page: true)->json('data');

        $this->assertNotSame('bank', $data['from'] ?? null, 'the saved refusal is not the answer about this product');
        $this->assertNotEmpty($this->calls, 'the product was read and answered from');
        // A Shopify product has no short description: its description is what the writer reads.
        $input = collect($this->calls)->map(fn (array $call): ?array => json_decode($call['user'], true))->first(fn (?array $input): bool => isset($input['products']));
        $this->assertSame('מברגה נטענת.', $input['products'][0]['about'] ?? null, 'the description reaches the writer');
        $this->assertSame('p1', $input['viewing'] ?? null, 'and it is told this is the product on the page');
        $this->assertSame(1, $this->inShop(fn () => AssistantAnswer::query()->count()), 'and nothing about this product is kept for the next one');
    }

    /**
     * A WooCommerce product has a short description and a full one, with its questions and answers at the end. Asked on
     * its page, the writer reads both: with the short one alone, "איך לשמור על המוצר הזה?" under a ladder whose page says
     * "איך מאחסנים את הסולם? פשוט לקפל ולשמור במקום יבש ומוגן" was answered "no instructions here" (Gueta, 17.11).
     * A list of results still reads a line each.
     */
    public function test_a_product_asked_about_on_its_page_is_read_from_its_full_description_to_its_questions(): void
    {
        $full = "### סולם אלומיניום רב־תכליתי מתקפל\nעשוי אלומיניום חזק.\n".str_repeat('מפרט טכני ויתרונות. ', 60)."\n### שאלות נפוצות\nשאלה: איך מאחסנים את הסולם?\nתשובה: פשוט לקפל ולשמור במקום יבש ומוגן.";
        $payload = fn (string $short): array => ['title' => 'סולם', 'short_description' => $short, 'description' => $full, 'categories' => [], 'attributes' => [], 'meta' => []];
        $this->product('801', 'סולם אלומיניום רב־תכליתי', $full, [], ['payload' => $payload('סולם אלומיניום קל משקל ומתקפל.')]);
        $this->product('802', 'סולם שלושה שלבים', $full, [], ['payload' => $payload('סולם ביתי.')]);
        $this->product('803', 'סולם ארבעה שלבים', $full, [], ['payload' => $payload('סולם ביתי גבוה.')]);
        $this->replies = [
            ['found' => true, 'answer' => 'פשוט לקפל את הסולם ולשמור אותו במקום יבש ומוגן.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'הסולם שבעמוד']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $this->askWith('איך לשמור על המוצר הזה?', ['801'], page: true)->assertOk();

        $about = (string) (json_decode($this->calls[0]['user'], true)['products'][0]['about'] ?? '');
        $this->assertStringStartsWith('סולם אלומיניום קל משקל ומתקפל.', $about, 'the short description first');
        $this->assertStringContainsString('פשוט לקפל ולשמור במקום יבש ומוגן', $about, 'and the full one, to the answer at its end');

        $this->calls = [];
        $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => []]];
        $this->askWith('איזה סולם הכי מתאים?', ['801', '802', '803']);

        $products = json_decode($this->calls[0]['user'], true)['products'] ?? [];
        $this->assertNotEmpty($products);
        foreach ($products as $product) {
            $this->assertStringNotContainsString('שאלות נפוצות', (string) ($product['about'] ?? ''), 'a list of results reads the short line of each');
        }
    }

    /**
     * Earrings whose page says only "שני חלקים, חלק אחורי מניפה", while the description of their picture, kept since the
     * scan, says which part is the stud and which the fan: asked "מה הכוונה שני חלקים" with them alone on the screen, the
     * question is about them, and the writer reads what the picture shows. It had said the phrase again in other words,
     * among five other products the words found (Rocks, 18.11). A question that asks for products is no question about it.
     */
    public function test_a_word_of_the_one_product_on_the_screen_is_asked_about_it_and_its_picture_is_read(): void
    {
        $earrings = $this->product('951', 'עגילים צמודים, שני חלקים, חלק אחורי מניפה משובצת יהלומים', 'עגילים זהב לבן 14 קארט.', [], ['price' => '6300.00', 'in_stock' => true]);
        $this->product('952', 'תליון שני לבבות', 'תליון זהב.', [], ['price' => '900.00', 'in_stock' => true]);
        $this->inShop(fn () => RetrievalImage::query()->create(['product_id' => $earrings->id, 'external_id' => '951', 'title' => 'x', 'image_url' => 'https://x.test/e.jpg', 'url_hash' => 'e',
            'caption' => 'עגילים צמודים בשני חלקים. החלק העליון הוא סטוד, והחלק התחתון הוא מניפה של זרועות דקות.', 'captioned_at' => now()]));
        $this->app->bind(ProductsNear::class, fn () => new class implements ProductsNear
        {
            public function near(string $shopId, string $question, int $limit): array
            {
                return ['952'];
            }
        });
        $this->replies = [
            ['found' => true, 'answer' => 'החלק הקדמי הוא עגיל צמוד, והחלק האחורי הוא מניפה.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'העגילים שעל המסך']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $this->askWith('מה הכוונה שני חלקים', ['951'])->assertOk();

        $given = json_decode($this->calls[0]['user'], true);
        $this->assertCount(1, $given['products'], 'the earrings alone: what the words find elsewhere is not what was asked');
        $this->assertSame('p1', $given['viewing'] ?? null);
        $this->assertStringContainsString("\nPicture: עגילים צמודים בשני חלקים. החלק העליון הוא סטוד", (string) $given['products'][0]['about']);

        $this->calls = [];
        $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => []]];
        $this->askWith('יש עוד תליונים?', ['951']);

        $titles = array_column(json_decode($this->calls[0]['user'], true)['products'] ?? [], 'title');
        $this->assertSame('תליון שני לבבות', $titles[0] ?? null, 'a question asking for products: what the store\'s search finds, first');
    }

    /**
     * A ring of one size whose page offers it a resize away ("מידה נוכחית: 48", "הגדלה ל 50"), kept outside its
     * variants and read off its page: asked "can it be made 59?" there, the writer and the checker have its sizes, in
     * either mode, and the sizes it names stand (Rocks, 15.11: the answer was the store's general one). "להגדיל" reads as
     * a store question, which in dynamic mode kept no product: the ring of the page stays all the same.
     */
    public function test_a_size_asked_on_a_rings_page_is_answered_from_the_sizes_its_page_offers(): void
    {
        $ring = $this->product('901', 'טבעת סוליטר משובצת אמרלד', 'טבעת זהב לבן 14 קארט, משקל 2.75 גרם.', [], ['price' => '2950.00', 'in_stock' => true]);
        $this->product('902', 'טבעת בלי מידה מהעמוד', 'טבעת.', [], ['price' => '900.00', 'in_stock' => true]);
        $this->inShop(fn () => CatalogProductChoice::query()->create(['shop_id' => $this->shop->id, 'product_id' => $ring->id, 'fields' => [], 'size' => 48, 'size_label' => 'מידה נוכחית', 'sizes' => [46, 47, 48, 49, 50]]));

        foreach (['full', AnswerContext::DYNAMIC] as $mode) {
            Settings::set('assistant.context_mode', $mode);
            $this->calls = [];
            $this->replies = [
                ['found' => true, 'answer' => 'הטבעת הזו במידה 48, ואפשר להזמין אותה במידות 46 עד 50, כך שמידה 59 אינה אפשרית בה.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'הטבעת שבעמוד']]],
                ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
            ];

            $data = $this->askWith('אפשר להגדיל למידה 59?', ['901', '902'], page: true)->assertOk()->json('data');

            $writer = json_decode($this->calls[0]['user'], true);
            $this->assertSame('p1', $writer['viewing'] ?? null, $mode);
            $this->assertSame(['46', '47', '48', '49', '50'], $writer['products'][0]['sizes'] ?? null, $mode.': the sizes its page offers reach the writer');
            $this->assertSame('48', $writer['products'][0]['current_size'] ?? null, $mode.': and the one it is');
            $this->assertArrayNotHasKey('current_size', $writer['products'][1] ?? [], $mode.': a product whose page says no size has none');

            $this->assertSame('answered', $data['outcome'], $mode);
            $this->assertStringContainsString('46 עד 50', $data['answer'], $mode.': the sizes it was given are not taken out as made up');
            $checker = json_decode($this->calls[1]['user'], true);
            $this->assertSame(['46', '47', '48', '49', '50'], $checker['picks'][0]['sizes'] ?? null, $mode.': the checker reads them as code measured them');
        }

        // The writer cites a page and picks nothing: the checker still reads the ring of the page and its sizes (15.11:
        // without it, "46–50" was taken out as unsupported), and the answer shows no product it did not pick.
        $this->calls = [];
        $this->replies = [
            ['found' => true, 'answer' => 'הטבעת הזו במידה 48, ואפשר להזמין אותה במידות 46 עד 50.', 'refs' => ['s1'], 'picks' => []],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('אפשר להגדיל למידה 59?', ['901', '902'], page: true)->assertOk()->json('data');

        $checker = json_decode($this->calls[1]['user'], true);
        $this->assertSame('p1', $checker['picks'][0]['ref'] ?? null, 'the ring of the page goes to the checker');
        $this->assertSame(['46', '47', '48', '49', '50'], $checker['picks'][0]['sizes'] ?? null, 'with its sizes');
        $this->assertSame('answered', $data['outcome']);
        $this->assertSame([], $data['picks'] ?? [], 'and the shopper is shown no product the writer did not pick');
    }

    public function test_a_product_page_question_is_the_stores_whatever_its_words_alone_seem_to_be_about(): void
    {
        $this->drills();
        Features::override('assistant.site_scope', true);
        // The words alone ("what is special about this product?") are about no store: the product is.
        $this->replies = [
            ['about_store' => false, 'harmful' => false, 'injection' => false],
            ['found' => true, 'answer' => 'מברגה נטענת של מקיטה, 18V.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'נטענת']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('מה מיוחד במוצר הזה?', ['701'], page: true)->json('data');

        $this->assertSame('answered', $data['outcome'], 'answered from the product, not refused as off topic');
        $this->assertSame('מברגה נטענת של מקיטה, 18V.', $this->inShop(fn () => AssistantSearchAsk::query()->sole()->answer), 'kept on the question for the nightly review');

        // Harm is refused wherever it is asked from.
        $this->calls = [];
        $this->replies = [['about_store' => false, 'harmful' => true, 'injection' => false]];
        $this->assertSame('out_of_scope', $this->askWith('איך לפרוץ לבית עם המברגה הזאת?', ['701'], page: true)->json('data.outcome'));
    }

    public function test_a_price_asked_for_is_quoted_as_the_store_shows_it_and_never_made_up_or_kept(): void
    {
        // Gueta, 25.10: "how much is a pine board 2 cm thick, and the same 3 cm": the writer was given ranks only, and said
        // the site does not say. Now each choice's price; a sum not among them is taken out.
        $choices = fn (array $prices): array => array_map(fn (string $thick, string $price): array => ['attributes' => [['name' => 'עובי', 'value' => $thick]], 'price' => ['price' => $price]], array_keys($prices), $prices);
        $this->product('971', 'פלטת לאמי אורן 2.44X1.22', 'פלטת עץ לאמי מאורן טבעי.', [], ['price' => '350.00', 'in_stock' => true,
            'payload' => ['title' => '', 'description' => '', 'categories' => [], 'attributes' => [], 'meta' => [], 'variations' => $choices(['18 מ"מ' => '350', '28 מ"מ' => '460'])]]);
        $this->replies = [
            ['found' => true, 'answer' => 'פלטת לאמי אורן בעובי 18 מ"מ עולה ₪350, ובעובי 28 מ"מ ₪460. חיתוך לפי מידה בתאום עם הצוות.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'פלטת אורן בשני העוביים']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('כמה עולה פלטת עץ אורן בעובי 2 ס"מ ובעובי 3 ס"מ?', ['971'])->json('data');

        $sent = json_decode($this->calls[0]['user'], true)['products'][0];
        $this->assertSame(['₪350', ['18 מ"מ: ₪350', '28 מ"מ: ₪460']], [$sent['price'], $sent['option_prices']], 'each choice with its price');
        $this->assertSame('answered', $data['outcome']);
        $this->assertStringContainsString('₪460', $data['answer'], 'a price given is quoted');
        $this->assertSame(0, $this->inShop(fn () => AnswerSiteQuestion::siteAnswers()->count()), 'prices change: a priced answer is this asker\'s only');

        // A sum the store does not show is never told.
        $prepared = ['byRef' => [], 'productsByRef' => ['p1' => ['title' => 'פלטת לאמי אורן', 'about' => 'פלטה.', 'external_id' => '971']], 'forModel' => [['ref' => 'p1', 'price' => '₪350', 'option_prices' => ['18 מ"מ: ₪350']]]];
        $screened = app(AnswerSiteQuestion::class)->screen(['answer' => 'הפלטה עולה ₪350. היא פלטת לאמי מאורן. חיתוך לגודל אחר יעלה ₪210.', 'picks' => [['ref' => 'p1', 'why' => 'פלטה']]], $prepared, 'כמה עולה?', 'full');
        $this->assertSame('הפלטה עולה ₪350. היא פלטת לאמי מאורן.', $screened['answer'], 'the made-up sum goes, the store\'s price stays');
    }

    public function test_a_question_that_depends_on_the_hour_is_told_the_stores_clock_and_never_kept(): void
    {
        // Rocks, 2.11: "are you open now" was answered "write us what time it is", and that was saved for everyone.
        $this->shop->forceFill(['timezone' => 'Asia/Jerusalem'])->save();
        Carbon::setTestNow(Carbon::parse('2026-11-02 17:31:00', 'UTC'));
        $answer = 'החנות סגורה עכשיו ונפתח שוב מחר בבוקר.';
        $reply = ['found' => true, 'answer' => $answer, 'refs' => ['s1']];
        $check = ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true];
        $this->replies = [$reply, $check];

        $data = $this->ask('החנות פתוחה עכשיו')->json('data');

        $this->assertSame(['day' => 'Monday', 'date' => '2026-11-02', 'time' => '19:31'], json_decode($this->calls[0]['user'], true)['now'], 'the store\'s hour, not the server\'s');
        $this->assertSame(json_decode($this->calls[0]['user'], true)['now'], json_decode($this->calls[1]['user'], true)['now'], 'the checker reads the same clock');
        $this->assertSame('answered', $data['outcome']);
        $this->assertSame(0, $this->inShop(fn () => AnswerSiteQuestion::siteAnswers()->count()), 'this moment\'s answer is this asker\'s only');

        // Asked again later: a new answer, never the one before.
        $this->replies = [$reply, $check];
        Carbon::setTestNow(Carbon::parse('2026-11-03 08:00:00', 'UTC'));
        $this->ask('החנות פתוחה עכשיו');
        $this->assertSame('10:00', json_decode($this->calls[2]['user'], true)['now']['time']);

        // A question that does not depend on the hour is told none, and is kept.
        $this->replies = [['found' => true, 'answer' => 'צריך בערך שלושים ברגים למטר.', 'refs' => ['s1']], $check];
        $this->ask('כמה ברגים צריך למטר דק?');
        $this->assertArrayNotHasKey('now', json_decode($this->calls[4]['user'], true));
        $this->assertSame(1, $this->inShop(fn () => AnswerSiteQuestion::siteAnswers()->count()));

        Carbon::setTestNow();
    }

    public function test_asked_if_the_store_is_open_its_opening_hours_are_read_beside_its_clock(): void
    {
        // Rocks, 8.11: "החנות פתוחה עכשיו" was told "I don't know", though its pages' footer and its own answers gave the
        // hours: the words never said "שעות פתיחה", so the page that gives them was never read.
        $this->shop->forceFill(['timezone' => 'Asia/Jerusalem'])->save();
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $contact = $this->article('700', 'צור קשר', 'כתובת החנות: שבזי 31 תל אביב. ראשון-חמישי 10.30-18.30, שישי 10-15. שעות פתיחה מעודכנות באתר.');
        $this->byText['מה שעות הפתיחה של החנות?'] = [['source' => 'content', 'source_id' => $contact->id, 'external_id' => '700', 'title' => 'צור קשר', 'text' => 'כתובת החנות: שבזי 31 תל אביב. ראשון-חמישי 10.30-18.30, שישי 10-15.', 'similarity' => 0.66]];
        $this->replies = [
            ['found' => true, 'answer' => 'כן, החנות פתוחה עכשיו, עד 18:30.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->ask('החנות פתוחה עכשיו')->json('data');

        $this->assertContains('מה שעות הפתיחה של החנות?', $this->searched, 'its opening hours are looked up by meaning too');
        $sent = json_decode($this->calls[0]['user'], true);
        $this->assertSame('14:00', $sent['now']['time']);
        $this->assertStringContainsString('10.30-18.30', implode("\n", array_column($sent['passages'], 'text')), 'the hours reach the writer beside the clock');
        $this->assertSame('answered', $data['outcome']);

        // which questions are about the store being open, and which are not
        foreach (['החנות פתוחה עכשיו' => true, 'אתם פתוחים בשישי?' => true, 'מתי אתם נסגרים היום?' => true, 'are you open on Sunday?' => true,
            'שמלה פתוחה בגב' => false, 'טבעת פתוחה מזהב' => false, 'open back dress' => false] as $question => $open) {
            $this->assertSame($open, AnswerSiteQuestion::asksIfOpen($question), $question);
        }

        Carbon::setTestNow();
    }

    public function test_a_number_any_given_page_states_stays_and_a_bare_yes_never_outlives_what_it_answered(): void
    {
        // Rocks, 25.10: "14 יום" is in the line every product page prints, not in the two pages the writer cited, which
        // say "שבועיים"; the sentence that answered was taken out and "כן." was left in front of the exceptions.
        $prepared = ['byRef' => [
            's1' => ['title' => 'מידע שמופיע בכל דפי המוצר באתר', 'text' => '- החזר כספי מלא תוך 14 יום', 'source' => 'content', 'source_id' => '1'],
            's2' => ['title' => 'תקנון', 'text' => 'ניתן להחזיר מוצר תוך שבועיים. לא ניתן להחזיר מוצר שנעשה בו שימוש.', 'source' => 'content', 'source_id' => '2'],
        ], 'productsByRef' => [], 'forModel' => []];
        $screen = fn (string $answer): array => app(AnswerSiteQuestion::class)->screen(['answer' => $answer, 'refs' => ['s2']], $prepared, 'אפשר להחזיר?', 'full');

        $kept = $screen('כן. אפשר להחזיר מוצר ולקבל החזר כספי מלא בתוך 14 יום. מוצר שנעשה בו שימוש לא ניתן להחזיר.');
        $this->assertSame('כן. אפשר להחזיר מוצר ולקבל החזר כספי מלא בתוך 14 יום. מוצר שנעשה בו שימוש לא ניתן להחזיר.', $kept['answer'], 'a number a given page states is the store\'s, cited or not');

        $trimmed = $screen('כן. אפשר להחזיר תוך 30 יום. מוצר שנעשה בו שימוש לא ניתן להחזיר, ואפשר לפנות לצוות החנות לכל שאלה.');
        $this->assertSame('מוצר שנעשה בו שימוש לא ניתן להחזיר, ואפשר לפנות לצוות החנות לכל שאלה.', $trimmed['answer'], 'the made-up number goes, and the "כן." it answered with it');
    }

    public function test_the_questions_shown_to_others_are_mended_once_and_never_changed_into_another(): void
    {
        $saved = fn (string $question): AssistantAnswer => $this->inShop(fn () => AssistantAnswer::query()->create(['shop_id' => $this->shop->id, 'question' => $question, 'question_key' => Question::key($question),
            'answer' => 'תשובה.', 'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN, 'source' => 'store', 'shared_at' => now(), 'asked_count' => 2, 'visitors' => 2, 'prompt_version' => 7, 'last_asked_at' => now()]));
        $typo = $saved('אפשר להחיזר מוצר?');
        $fine = $saved('תוך כמה זמן מגיע המשלוח?');
        $this->replies = [['questions' => [['id' => 'q1', 'corrected' => 'אפשר להחזיר מוצר?'], ['id' => 'q2', 'corrected' => 'מה שעות הפתיחה של החנות בסניף?']]]];

        app(MendShownQuestions::class)->handle($this->shop->id);

        $this->assertSame('אפשר להחזיר מוצר?', $typo->fresh()->flags['corrected'] ?? null, 'the slip mended');
        $this->assertArrayNotHasKey('corrected', (array) $fine->fresh()->flags, 'a wording that changes the question is never kept');
        $this->assertTrue((bool) ($fine->fresh()->flags['mend_read'] ?? false));
        $this->assertCount(1, $this->calls, 'all the shop\'s questions in one call');
        $this->assertNull(app(MendShownQuestions::class)->handle($this->shop->id), 'never asked twice');
        $this->assertTrue(AnswerSiteQuestion::pointsBack('מה הכי כדאי ומתאים לי מבין התוצאות לחיפוש "ספר"?'), 'the drawer\'s question about one search\'s results is never kept for others');
    }

    public function test_code_reads_the_results_before_the_model_ranks_never_prices(): void
    {
        $this->drills();
        $this->replies = [
            ['found' => true, 'answer' => 'למברגה ביתית, מברגה איינהל.', 'refs' => [], 'picks' => [['ref' => 'p3', 'why' => 'מתאימה לבית']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $this->askWith('איזו מברגה מתאימה לבית?', ['701', '704', '702', '703'])->assertOk();

        $input = json_decode($this->calls[0]['user'], true);
        $this->assertNull($input['analysis']['asks']);
        $this->assertSame(['p2', 'p3', 'p1'], $input['analysis']['in_stock_cheapest_first']);
        $this->assertSame(['p4'], $input['analysis']['out_of_stock']);
        $this->assertSame(1, $input['products'][1]['price_rank']);
        $this->assertSame('₪240', $input['products'][2]['price'], 'the price as the store shows it, so a shopper who asks what it costs is told');
        $this->assertStringContainsString('"price_rank":2', $this->calls[1]['user'], 'the checker sees the measure the why rests on');
        $this->assertStringContainsString('"price":"₪240"', $this->calls[1]['user'], 'and the price it holds the answer to');
    }

    public function test_what_is_recommended_most_is_what_the_stores_customers_choose(): void
    {
        // Sela, 26.10: "מה הספר הכי מומלץ" was the writer's taste; it is what the store's customers buy and add to the cart
        $this->drills();
        $this->app->instance(ProductsPopular::class, new class implements ProductsPopular
        {
            public function scores(string $shopId, ?array $among = null): array
            {
                return ['702' => 1.0, '701' => 0.4];
            }
        });
        $this->replies = [
            ['found' => true, 'answer' => 'המברגה של איינהל היא מה שהלקוחות שלנו בוחרים הכי הרבה.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'הלקוחות בוחרים בה הכי הרבה']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('איזו מברגה הכי מומלצת?', ['704'])->json('data');

        $input = json_decode($this->calls[0]['user'], true);
        $this->assertSame('popular', $input['analysis']['asks']);
        $this->assertSame(['p1', 'p2'], $input['analysis']['popular_first'], 'the most popular first, though the shopper was shown only a bit');
        $this->assertSame('מקדחה/מברגה איינהל', $input['products'][0]['title']);
        $this->assertSame(['702'], array_column($data['picks'], 'external_id'));
        $this->assertStringContainsString('popular_first', $this->calls[1]['user'], 'the checker holds "the most popular" to it');
        $this->assertFalse(AnswerSiteQuestion::asksPopular('איזו מברגה הכי זולה?'));
        $this->assertTrue(AnswerSiteQuestion::asksPopular('what is your best seller?'));
    }

    public function test_a_product_whose_own_page_the_answer_cites_is_shown_though_it_was_not_given(): void
    {
        // Sela, 26.10: "ספרים על חיים טובים?" recommended "12 כללים לחיים" from its page, and showed no card of it
        $this->drills();
        $einhell = $this->inShop(fn () => CatalogProduct::query()->where('external_id', '702')->value('id'));
        $this->passages = [['source' => 'product', 'source_id' => $einhell, 'external_id' => '702', 'title' => 'מקדחה/מברגה איינהל', 'text' => 'מברגה רוטטת, טובה לבית.', 'similarity' => 0.7]];
        $this->replies = [
            ['found' => true, 'answer' => 'לבית כדאי מברגה רוטטת, כמו של איינהל.', 'refs' => ['s1'], 'picks' => []],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('מברגה לבית?', ['701', '704'])->json('data');

        $this->assertSame(['702'], array_column($data['picks'], 'external_id'), 'the product of the page it rests on, as a card');
        $this->assertStringContainsString('"why":"","brand":"Einhell"', $this->calls[1]['user'], 'and the checker judged it as any pick');
    }

    private function drills(): void
    {
        $this->product('701', 'מברגה מקיטה 18V', 'מברגה נטענת.', [], ['price' => '890.00', 'brand' => 'Makita', 'in_stock' => true]);
        $this->product('702', 'מקדחה/מברגה איינהל', 'מברגה רוטטת.', [], ['price' => '240.00', 'brand' => 'Einhell', 'in_stock' => true, 'on_sale' => true]);
        $this->product('703', 'מברגה זולה שאזלה', 'מברגה.', [], ['price' => '99.00', 'in_stock' => false]);
        $this->product('704', 'ביט למברגה 25 מ"מ', 'ביט.', [], ['price' => '7.00', 'in_stock' => true]);
    }

    /**
     * @param  list<string>  $products
     * @param  list<string>  $previous  what the drawer's turn before showed
     */
    private function askWith(string $question, array $products, array $previous = [], bool $page = false, array $extra = []): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['question' => $question, 'vid' => self::VID, 'locale' => 'he', 'products' => $products] + ($previous === [] ? [] : ['previous' => $previous]) + ($page ? ['page' => 1] : []) + $extra, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_answers_prices_are_written_as_the_storefronts_cards_write_them(): void
    {
        // Naot QA, 16.11: the answer said "AUD 202.46", its card "$202". The writer is given each price as the cards write
        // it (the currency and sign the drawer sends), so what it quotes is what the card beside it says
        $this->product('972', 'Arthur Sandal', 'A leather sandal.', [], ['price' => '202.46', 'currency' => 'AUD', 'in_stock' => true]);
        $this->replies = [
            ['found' => true, 'answer' => 'Arthur Sandal is $202.46.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'the sandal']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->askWith('How much is the Arthur sandal?', ['972'], [], false, ['currency' => 'USD', 'currency_sign' => '$']);
        $this->assertSame('$202.46', json_decode($this->calls[0]['user'], true)['products'][0]['price'], 'the cards\' sign, never "AUD 202.46"');

        // a sign the cards put after the number, and an older drawer that sends none: the product's own, as before
        $this->assertSame(['202.46 €', 'AUD 202.46', '₪350', '$1,299.90'], [
            AnswerSiteQuestion::priceAs(202.46, 'EUR', '€', true),
            AnswerSiteQuestion::priceAs(202.46, 'AUD'),
            AnswerSiteQuestion::priceAs(350.0, 'ILS', '₪'),
            AnswerSiteQuestion::priceAs(1299.9, 'USD', '$'),
        ]);
    }
}
