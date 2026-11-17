<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Actions\BuildTradeGlossary;
use App\Modules\Assistant\Filament\Merchant\Pages\AboutStore;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Sources\ContentDocuments;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every store's bank of the terms of its trade, from its own data and no word list (as Aviad asked, 16.11): code
 * gathers the words, one family explains, the other keeps what is correct, the team edits or hides, and the assistant
 * reads only the lines whose term the question names. A jeweller and a textile store, Hebrew and English.
 */
final class TradeTermsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{provider: AiProviderName, model: string, system: string, user: string}> */
    public array $calls = [];

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'אבני חן', 'content_locale' => 'he']);
        Features::override('assistant.trade_terms', true, $this->shop->id);
        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly TradeTermsTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = compact('provider', 'model', 'system', 'user');
                $input = json_decode($user, true);

                // the checker keeps what is correct: not the wrong purity
                if (isset($input['terms'])) {
                    return new ModelReply(['keep' => array_values(array_map(fn (array $t): string => $t['id'], array_filter($input['terms'], fn (array $t): bool => ! str_contains($t['text'], '75%')))), 'dropped' => []], 600, 30);
                }

                $by = array_column($input['candidates'], 'id', 'term');
                $terms = [];
                foreach (['14K' => '14K הוא זהב בטוהר של 58.5%: 14 חלקי זהב מתוך 24.', 'VS' => 'VS היא דרגת ניקיון של יהלום: פגמים זעירים שקשה לראות גם בהגדלה.', 'כותנה' => 'כותנה היא סיב טבעי שנושם ונעים למגע.', 'יהלום מעבדה' => 'יהלום מעבדה הוא יהלום אמיתי שגודל במעבדה, באותו הרכב כימי כמו יהלום מכרה.'] as $term => $text) {
                    if (isset($by[$term])) {
                        $terms[] = ['id' => $by[$term], 'text' => $text];
                    }
                }
                if (isset($by['18K'])) {
                    $terms[] = ['id' => $by['18K'], 'text' => '18K הוא זהב בטוהר של 75% וזה הכי טוב.'];
                }
                if (isset($by['GH'])) {
                    $terms[] = ['id' => $by['GH'], 'text' => 'GH עולה 300 ₪ יותר מכל דרגה אחרת אצלנו.'];
                }
                $terms[] = ['id' => 'c999', 'text' => 'מילה שלא נשלחה, ולא תיכנס לבנק בשום מקרה.'];

                return new ModelReply(['terms' => $terms], 2000, 400);
            }
        });
    }

    public function test_the_words_that_may_be_terms_are_gathered_from_the_stores_own_data(): void
    {
        $this->catalog();

        $words = $this->inShop(fn (): array => BuildTradeGlossary::candidates());
        $terms = array_column($words, 'from', 'term');

        $this->assertSame('asked', $terms['GH'] ?? null, 'what a shopper asked the meaning of, first');
        $this->assertSame('code', $terms['14K'] ?? null, 'a short code in the titles');
        $this->assertSame('asked', $terms['VS'] ?? null, 'in the titles too, but a shopper asked: once, as asked');
        $this->assertArrayHasKey('18K', $terms);
        $this->assertSame('percent', $terms['כותנה'] ?? null, 'what a percentage is of');
        $this->assertArrayHasKey('יהלום מעבדה', $terms, 'a pair the titles repeat');
        $this->assertArrayNotHasKey('2025-03-23', $terms, 'no date');
        $this->assertArrayNotHasKey('225084', $terms, 'no number alone');
        $this->assertArrayNotHasKey('5 ליטר', $terms, 'no measure alone');
        $this->assertSame($words, $this->inShop(fn (): array => BuildTradeGlossary::candidates()), 'the same store gives the same words');
    }

    public function test_what_a_question_asks_the_meaning_of(): void
    {
        $this->assertSame(['14K'], BuildTradeGlossary::askedTerms('מה זה 14K?'));
        $this->assertSame(['GH', 'VS'], BuildTradeGlossary::askedTerms('מה ההבדל בין GH ל-VS?'));
        $this->assertSame(['merino wool'], BuildTradeGlossary::askedTerms('What is merino wool?'));
        $this->assertSame(['IPX7'], BuildTradeGlossary::askedTerms('what does IPX7 mean'));
        $this->assertSame([], BuildTradeGlossary::askedTerms('יש לכם טבעות עד 2000?'));
    }

    public function test_only_the_lines_whose_term_the_question_names(): void
    {
        $lines = ['- 14K: זהב בטוהר 58.5%.', '- יהלום מעבדה: יהלום שגודל במעבדה.', '- זהב לבן: סגסוגת של זהב.', '- merino: a fine wool.'];

        $this->assertSame(['- 14K: זהב בטוהר 58.5%.'], BuildTradeGlossary::named('מה זה 14k?', $lines));
        $this->assertSame(['- יהלום מעבדה: יהלום שגודל במעבדה.'], BuildTradeGlossary::named('יש לכם יהלומי מעבדה?', $lines), 'its plural and a final letter');
        $this->assertSame([], BuildTradeGlossary::named('טבעת זהב עד 2000', $lines), 'a word of a term is not the term');
        $this->assertSame(['- זהב לבן: סגסוגת של זהב.'], BuildTradeGlossary::named('ומה עם הזהב הלבן?', $lines));
        $this->assertSame(['- merino: a fine wool.'], BuildTradeGlossary::named('Is merino itchy?', $lines));
    }

    public function test_the_bank_is_written_checked_by_the_other_family_answered_from_and_never_asked_twice(): void
    {
        $this->catalog();

        $run = app(BuildTradeGlossary::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value);
        // the stronger model writes, the other family checks (v3, 17.11)
        $this->assertSame([AiProviderName::Anthropic, AiProviderName::OpenAi], array_column($this->calls, 'provider'), 'the other family checks');
        $this->assertSame(file_get_contents(__DIR__.'/../Prompts/trade_terms.v'.BuildTradeGlossary::PROMPT_VERSION.'.md'), $this->calls[0]['system']);
        $checked = array_column(json_decode($this->calls[1]['user'], true)['terms'], 'term');
        $this->assertNotContains('GH', $checked, 'code drops a line with a sum before the checker sees it');
        $this->assertCount(count(array_unique($checked)), $checked);

        $body = (string) $this->entry()->body;
        $this->assertStringContainsString('- 14K: 14K הוא זהב בטוהר של 58.5%', $body);
        $this->assertStringContainsString('- כותנה: ', $body);
        $this->assertStringNotContainsString('18K', $body, 'the checker keeps only what is correct');
        $this->assertStringNotContainsString('לא נשלחה', $body, 'never a word that was not sent');
        $this->assertSame('מונחים מקצועיים: הסבר כללי', (string) $this->entry()->title);

        // Never asked twice: no new word, no model; a new product's code alone is sent.
        $this->calls = [];
        app(BuildTradeGlossary::class)->handle($this->shop->id);
        $this->assertSame([], $this->calls);
        $this->inShop(fn () => collect(['21', '22'])->each(fn (string $id) => $this->product($id, "טבעת IPX7 דגם {$id}")));
        app(BuildTradeGlossary::class)->handle($this->shop->id);
        $sent = array_column(json_decode($this->calls[0]['user'], true)['candidates'], 'term');
        $this->assertContains('IPX7', $sent);
        $this->assertSame([], array_intersect($sent, ['14K', '18K', 'VS', 'GH', 'כותנה', 'יהלום מעבדה']), 'a word decided before is never sent again');

        // The assistant reads only the lines the question names, as a page.
        $passages = $this->knowledge('מה ההבדל בין 14K ל-VS?');
        $this->assertCount(1, $passages);
        $this->assertStringContainsString('- 14K:', $passages[0]['text']);
        $this->assertStringContainsString('- VS:', $passages[0]['text']);
        $this->assertStringNotContainsString('כותנה', $passages[0]['text']);
        $this->assertNull($passages[0]['from']);
        $this->assertSame([], $this->knowledge('יש טבעות זהב עד 2000?'), 'nothing named, nothing added');

        // Never found by meaning: its words (and the words already decided) are not a document of the search.
        $documents = $this->inShop(fn (): array => iterator_to_array((new ContentDocuments)->documents($this->shop->id), false));
        $this->assertSame([], array_filter($documents, fn ($d): bool => str_contains((string) $d->text, '58.5%')));

        // The team's line is kept as written; a hidden term is told no more and never written again.
        $this->inShop(fn () => app(BuildShopKnowledge::class)->editTerm('14k', 'זהב 14 קראט: 58.5% זהב טהור. אצלנו כל התכשיטים עם חותמת.'));
        $this->inShop(fn () => app(BuildShopKnowledge::class)->hideTerm('כותנה'));
        $this->calls = [];
        app(BuildTradeGlossary::class)->handle($this->shop->id);
        $body = (string) $this->entry()->body;
        $this->assertStringContainsString('- 14K: זהב 14 קראט: 58.5% זהב טהור.', $body);
        $this->assertStringNotContainsString('כותנה', $body);
        $this->assertSame([], $this->knowledge('האם זה 100% כותנה?'));
        $this->inShop(fn () => app(BuildShopKnowledge::class)->showTerm('כותנה'));

        // Written with another prompt: its words decided again, all but the team's line
        $this->inShop(function (): void {
            $state = app(BuildShopKnowledge::class)->termState();
            app(BuildShopKnowledge::class)->terms($state['rows'], $state['seen'], BuildTradeGlossary::PROMPT_VERSION - 1);
        });
        $this->calls = [];
        app(BuildTradeGlossary::class)->handle($this->shop->id);
        $sent = array_column(json_decode($this->calls[0]['user'], true)['candidates'], 'term');
        $this->assertContains('VS', $sent, 'what the model wrote is written again');
        $this->assertNotContains('14K', $sent, 'what the team wrote is never sent');
        $this->assertStringContainsString('- 14K: זהב 14 קראט: 58.5% זהב טהור.', (string) $this->entry()->body);
        $this->assertSame(BuildTradeGlossary::PROMPT_VERSION, $this->inShop(fn (): int => app(BuildShopKnowledge::class)->termState()['version']));
        $this->assertStringContainsString('- כותנה: ', (string) $this->entry()->body);

        // Off for the store: none of it is read, but a quality run tries it in its own process.
        Features::override('assistant.trade_terms', false, $this->shop->id);
        $this->assertSame([], $this->knowledge('מה זה 14K?'));
        config(['assistant.try_terms' => true]);
        $this->assertCount(1, $this->knowledge('מה זה 14K?'));
        config(['assistant.try_terms' => null]);
    }

    public function test_a_checker_of_the_same_family_fails_the_run_and_off_asks_nothing(): void
    {
        $this->catalog();
        Settings::set('assistant.terms_check_provider', 'anthropic');
        $this->assertSame('failed', app(BuildTradeGlossary::class)->handle($this->shop->id)->status->value);
        $this->assertSame([], $this->calls);

        Settings::set('assistant.terms_check_provider', 'openai');
        Features::override('assistant.trade_terms', false, $this->shop->id);
        $this->assertSame('succeeded', app(BuildTradeGlossary::class)->handle($this->shop->id)->status->value);
        $this->assertSame([], $this->calls);

        // an operator's trial writes it with the flag off, to try it before shoppers read it
        app(BuildTradeGlossary::class)->handle($this->shop->id, trial: true);
        $this->assertCount(2, $this->calls);
    }

    public function test_the_merchant_sees_the_bank_edits_a_term_and_hides_one(): void
    {
        $this->catalog();
        app(BuildTradeGlossary::class)->handle($this->shop->id);

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);

        Livewire::test(AboutStore::class)
            ->assertOk()
            ->assertSee('בנק מונחים')
            ->call('show', 'terms')
            ->assertSee('14 חלקי זהב מתוך 24')
            ->assertDontSee('gpt-')
            ->assertDontSee('claude')
            ->call('startTerm', '14k')
            ->set('editTermText', 'זהב 14 קראט הוא 58.5% זהב טהור.')
            ->call('saveTerm')
            ->assertSee('זהב 14 קראט הוא 58.5% זהב טהור.')
            ->assertSee('נערך על ידכם')
            ->call('hideTerm', 'vs')
            ->assertSee('מוסתר: העוזר לא משתמש בו');

        $this->assertStringNotContainsString('- VS:', (string) $this->entry()->body);

        // a store without the bank sees no tab
        Features::override('assistant.trade_terms', false, $this->shop->id);
        Livewire::test(AboutStore::class)->assertOk()->assertDontSee('בנק מונחים');
    }

    /** A jeweller's and a textile store's words, with what a feed also brings: ids, dates, measures. */
    private function catalog(): void
    {
        $this->inShop(function (): void {
            $this->product('1', 'טבעת יהלום מעבדה 14K', ['attributes' => [['name' => 'ניקיון', 'options' => ['VS', 'SI']], ['name' => 'מק"ט', 'options' => ['225084']]], 'meta' => ['updated' => '2025-03-23']]);
            $this->product('2', 'עגילי יהלום מעבדה 14K', ['attributes' => [['name' => 'ניקיון', 'options' => ['VS']], ['name' => 'מק"ט', 'options' => ['225084']]]]);
            $this->product('3', 'צמיד יהלום מעבדה 18K VS', ['attributes' => [['name' => 'נפח', 'options' => ['5 ליטר']]]]);
            $this->product('4', 'שרשרת 18K', ['attributes' => [['name' => 'נפח', 'options' => ['5 ליטר']]]]);
            $this->product('5', 'שמיכה לתינוק', ['description' => '<p>100% כותנה, רכה ונעימה.</p>']);
            $this->product('6', 'מגבת קפוצ\'ון', ['description' => '<p>100% כותנה.</p>']);
            AssistantSearchAsk::query()->create(['shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'question' => 'מה ההבדל בין GH ל-VS?', 'products' => [], 'outcome' => 'answered', 'from' => 'model']);
        });
    }

    private function product(string $id, string $title, array $payload = []): void
    {
        CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://store.test/{$id}", 'price' => 1000, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$id, 'payload' => $payload]);
    }

    /** @return list<array<string, mixed>> the knowledge passages the question is given, of the bank */
    private function knowledge(string $question): array
    {
        $passages = $this->inShop(fn (): array => (new ReflectionMethod(AnswerSiteQuestion::class, 'knowledge'))->invoke(app(AnswerSiteQuestion::class), $question, $this->shop->id, []));
        $id = (string) $this->entry()->id;

        return array_values(array_filter($passages, fn (array $p): bool => $p['source_id'] === $id));
    }

    private function entry(): CatalogContent
    {
        return $this->inShop(fn (): CatalogContent => CatalogContent::query()->where('type', CatalogContent::KNOWLEDGE)->where('external_id', BuildShopKnowledge::TERMS)->firstOrFail());
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
