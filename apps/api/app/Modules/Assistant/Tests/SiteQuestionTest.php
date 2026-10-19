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
use App\Modules\Assistant\Contracts\ProductsNamed;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_the_checker_is_haiku_5_5_with_room_to_think_and_what_it_did_is_kept(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר מרובע.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];
        $this->ask('כמה ברגים צריך למטר דק?');

        $this->assertSame(['claude-haiku-5-5', 1500], [$this->calls[1]['model'], $this->calls[1]['max']], 'its thinking counts as output: 400 tokens would cut it short');
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
        $this->assertSame(['ran' => false, 'type' => 'store_info', 'skipped' => 'number_not_in_the_pages'], $checks[2]);

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

    public function test_nothing_near_the_question_asks_no_writer_and_is_asked_again_later(): void
    {
        $this->passages = [];

        $this->assertSame('no_info', $this->ask('יש משלוח לאילת?')->json('data.outcome'));
        $this->assertSame([], $this->calls);

        $this->passages = [['source' => 'content', 'source_id' => $this->inShop(fn () => CatalogContent::query()->value('id')), 'external_id' => '600', 'title' => 'משלוחים', 'text' => 'יש משלוח לכל הארץ, כולל אילת.', 'similarity' => 0.8]];
        $this->replies = [['found' => true, 'answer' => 'כן, יש משלוח לכל הארץ, כולל אילת.', 'refs' => ['s1']], ['supported' => true, 'on_topic' => true, 'safe' => true]];

        $this->assertSame('no_info', $this->ask('יש משלוח לאילת?')->json('data.outcome'), 'the same day: from memory');
        $this->travel((int) Settings::get('assistant.site_retry_days') + 1)->days();
        $this->assertSame('answered', $this->ask('יש משלוח לאילת?')->json('data.outcome'), 'later: asked again, the store may have added the page');
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
                return str_contains($question, 'עגילים') ? array_values(array_intersect($externalIds, ['801', '802', '804', '805'])) : [];
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
        $this->assertStringNotContainsString('240', $this->calls[0]['user'], 'ranks, never prices, reach the model');
        $this->assertStringContainsString('"price_rank":2', $this->calls[1]['user'], 'the checker sees the measure the why rests on');
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
    private function askWith(string $question, array $products, array $previous = [], bool $page = false): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['question' => $question, 'vid' => self::VID, 'locale' => 'he', 'products' => $products] + ($previous === [] ? [] : ['previous' => $previous]) + ($page ? ['page' => 1] : []), JSON_UNESCAPED_UNICODE));
    }
}
