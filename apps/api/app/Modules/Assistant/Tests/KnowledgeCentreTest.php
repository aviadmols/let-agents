<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Actions\CheckOwnKnowledge;
use App\Modules\Assistant\Filament\Merchant\Pages\AboutStore;
use App\Modules\Assistant\Jobs\CheckOwnKnowledgeJob;
use App\Modules\Assistant\Jobs\ReadPostKnowledgeJob;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\RepeatedText;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Enrichment\Contracts\ShopRules;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The knowledge centre, as the designer's panel lays it out: a line the team adds joins general
 * information and is checked against the site; the site's pages show what they say and how many
 * questions they answered; a post is added by its link; what was found on the site says where.
 */
final class KnowledgeCentreTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $near = [];

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'גואטה אביגדור']);
        $test = $this;

        $this->app->instance(Passages::class, new class($test) implements Passages
        {
            public function __construct(private readonly KnowledgeCentreTest $test) {}

            public function near(string $shopId, string $text, array $sources, int $limit): array
            {
                return $this->test->near;
            }
        });
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly KnowledgeCentreTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = $user;

                // p1 says otherwise; p9 was never sent.
                return new ModelReply(['conflicts' => [['id' => 'p1', 'why' => 'באתר: 10 ש"ח לחיתוך.'], ['id' => 'p9', 'why' => 'invented']]], 600, 30);
            }
        });

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
    }

    public function test_a_rule_the_team_writes_shows_its_plan_and_is_applied_on_approval(): void
    {
        // the rules live in another module (Taxonomy, through Enrichment\Contracts\ShopRules): here, what the screen does with them
        $rules = new class implements ShopRules
        {
            /** @var list<array<string, mixed>> */
            public array $rules = [];

            /** @var list<array{0: string, 1: bool}> */
            public array $decided = [];

            public function list(): array
            {
                return $this->rules;
            }

            public function add(string $text, ?int $userId): ?string
            {
                $this->rules[] = ['id' => 'r1', 'text' => $text, 'status' => 'proposed', 'note' => 'הספר הוא ביוגרפיה.', 'at' => now()->toIso8601String(), 'changes' => [
                    ['external_id' => '1', 'title' => 'מפגש עם עצמי בגיהינום', 'image' => null, 'url' => null, 'add' => ['ביוגרפיה'], 'remove' => ['אימה'], 'apart' => ['דרקולה'], 'alike' => []],
                ]];

                return 'r1';
            }

            public function decide(string $id, bool $approve, ?int $userId): void
            {
                $this->decided[] = [$id, $approve];
                $this->rules[0]['status'] = $approve ? 'applied' : 'rejected';
            }

            public function remove(string $id): void
            {
                $this->rules = [];
            }
        };
        $this->app->instance(ShopRules::class, $rules);

        $page = Livewire::test(AboutStore::class)
            ->call('show', 'rules')
            ->assertSee(__('assistant::ui.centre.rules.none'))
            ->set('newRule', 'הספר "מפגש עם עצמי בגיהינום" לא מתאים לספרים כמו "דרקולה"')
            ->call('addRule')
            ->assertSee(__('assistant::ui.centre.rules.status.proposed'))
            ->assertSee('הספר הוא ביוגרפיה.')
            ->assertSee('מפגש עם עצמי בגיהינום')->assertSee(__('assistant::ui.centre.rules.add').' ביוגרפיה')->assertSee(__('assistant::ui.centre.rules.remove').' אימה')->assertSee(__('assistant::ui.centre.rules.apart').' דרקולה')
            ->assertSee(__('assistant::ui.centre.rules.approve'));
        $this->assertSame('', $page->get('newRule'));

        $page->call('decideRule', 'r1', true)->assertSee(__('assistant::ui.centre.rules.status.applied'))->assertDontSee(__('assistant::ui.centre.rules.approve'));
        $this->assertSame([['r1', true]], $rules->decided);

        $page->call('removeRule', 'r1')->assertSee(__('assistant::ui.centre.rules.none'));
    }

    public function test_a_line_the_team_adds_joins_general_information_and_can_be_edited_and_removed(): void
    {
        Queue::fake();

        $page = Livewire::test(AboutStore::class)
            ->assertSee('להוסיף מידע')
            ->set('newInfo', 'חיתוך פלטות לפי מידה בחנות בלבד, 8 ש"ח לחיתוך.')
            ->call('addInfo')
            ->assertSet('newInfo', '')
            ->assertSee('חיתוך פלטות לפי מידה בחנות בלבד')
            ->assertSee('נבדק מול האתר');

        $id = $this->inShop(fn (): string => app(BuildShopKnowledge::class)->ownRows()[0]['id']);
        Queue::assertPushed(CheckOwnKnowledgeJob::class, fn (CheckOwnKnowledgeJob $job): bool => $job->shopId === $this->shop->id && $job->rowId === $id);
        $this->assertStringContainsString('- חיתוך פלטות לפי מידה', (string) $this->inShop(fn () => app(BuildShopKnowledge::class)->entries()[BuildShopKnowledge::OWN]->body), 'the assistant reads it');

        $page->call('startEdit', $id)->set('editText', 'חיתוך לפי מידה בחנות בלבד, 10 ש"ח לחיתוך.')->call('saveEdit')
            ->assertSee('10 ש"ח לחיתוך')
            ->call('removeInfo', $id)
            ->assertDontSee('10 ש"ח לחיתוך');

        $this->assertSame([], $this->inShop(fn (): array => app(BuildShopKnowledge::class)->ownRows()));
    }

    public function test_the_check_names_only_passages_it_sent_and_asks_once_for_the_same_input(): void
    {
        $this->near = [['source' => 'content', 'source_id' => '1', 'external_id' => 'p-1', 'title' => 'תקנון', 'text' => 'חיתוך: 10 ש"ח לחתך.', 'similarity' => 0.8]];
        $id = $this->inShop(fn (): string => (string) app(BuildShopKnowledge::class)->addOwn('חיתוך: 8 ש"ח לחתך.'));

        app(CheckOwnKnowledge::class)->handle($this->shop->id, $id);
        $check = $this->inShop(fn (): array => app(BuildShopKnowledge::class)->ownRows()[0]['check']);

        $this->assertSame('conflict', $check['status']);
        $this->assertSame([['title' => 'תקנון', 'why' => 'באתר: 10 ש"ח לחיתוך.']], $check['notes'], 'the title comes from code; p9 was never sent');
        $this->assertCount(1, $this->calls);

        // The same line against the same passages: no second call.
        $other = $this->inShop(fn (): string => (string) app(BuildShopKnowledge::class)->addOwn('חיתוך: 8 ש"ח לחתך.'));
        app(CheckOwnKnowledge::class)->handle($this->shop->id, $other);
        $this->assertCount(1, $this->calls, 'never asked twice');

        Livewire::test(AboutStore::class)->assertSee('באתר כתוב אחרת')->assertSee('באתר: 10 ש"ח לחיתוך.');
    }

    public function test_nothing_on_the_site_near_the_line_means_no_model_is_asked(): void
    {
        $this->near = [];
        $id = $this->inShop(fn (): string => (string) app(BuildShopKnowledge::class)->addOwn('אפשר לאסוף מהמחסן בשישי.'));

        app(CheckOwnKnowledge::class)->handle($this->shop->id, $id);

        $this->assertSame([], $this->calls);
        $this->assertSame('nothing', $this->inShop(fn (): string => app(BuildShopKnowledge::class)->ownRows()[0]['check']['status']));
    }

    public function test_pages_show_what_they_say_and_how_many_questions_they_answered(): void
    {
        $returns = $this->content(CatalogContent::PAGE, 'מדיניות החזרות', 'https://shop.example.co.il/returns/', '<p>החזרה תוך 14 יום באריזה המקורית.</p>');
        $this->content(CatalogContent::PAGE, 'אודות', 'https://shop.example.co.il/about/', '<p>חנות חומרי בניין.</p>');
        $this->inShop(function () use ($returns): void {
            AssistantAnswer::query()->create(['question_key' => 'a', 'question' => 'אפשר להחזיר?', 'answer' => 'כן', 'outcome' => 'answered', 'source' => 'store', 'status' => AssistantAnswer::SHOWN, 'asked_count' => 5, 'content_id' => $returns->id, 'prompt_version' => 1, 'last_asked_at' => now()]);
            AssistantAnswer::query()->create(['question_key' => 'b', 'question' => 'תוך כמה זמן מחזירים?', 'answer' => '14 יום', 'outcome' => 'answered', 'source' => 'store', 'status' => AssistantAnswer::SHOWN, 'asked_count' => 3, 'prompt_version' => 1, 'last_asked_at' => now(),
                'sources' => [['title' => 'מדיניות החזרות', 'url' => 'http://www.shop.example.co.il/returns', 'type' => 'page']]]);
        });

        Livewire::test(AboutStore::class)
            ->assertSeeInOrder(['מדיניות החזרות', 'החזרה תוך 14 יום באריזה המקורית.', 'ענה על 8 שאלות', 'אודות'])
            ->assertSee('ממתין לקריאה');
    }

    public function test_a_post_is_added_by_its_link_in_any_spelling_and_read_first(): void
    {
        Queue::fake();
        $post = $this->content(CatalogContent::POST, 'איך בוחרים לביד', 'https://shop.example.co.il/blog/lavid/', '<p>לביד סנדוויץ׳ עמיד יותר ללחות.</p>');

        Livewire::test(AboutStore::class)
            ->call('show', 'posts')
            ->assertSee('איך בוחרים לביד')
            ->set('postUrl', 'http://www.shop.example.co.il/blog/lavid?utm_source=x')
            ->call('addPost')
            ->assertSee('בתור לקריאה');

        $this->assertSame([(string) $post->id], $this->inShop(fn (): array => app(BuildShopKnowledge::class)->wanted()));
        Queue::assertPushed(ReadPostKnowledgeJob::class);

        Queue::fake();
        Livewire::test(AboutStore::class)->set('postUrl', 'https://shop.example.co.il/blog/not-here/')->call('addPost');
        Queue::assertNothingPushed();
    }

    public function test_what_product_pages_repeat_says_on_about_how_many(): void
    {
        $this->inShop(fn () => CatalogContent::query()->create([
            'type' => CatalogContent::KNOWLEDGE, 'external_id' => BuildShopKnowledge::REPEATED, 'title' => '', 'body' => '- משלוח עד 5 ימי עסקים לכל הארץ', 'hash' => 'h',
            'terms' => ['pages' => 10, 'products' => 1840, 'counts' => [RepeatedText::key('משלוח עד 5 ימי עסקים לכל הארץ') => 10], 'hidden' => []],
        ]));

        Livewire::test(AboutStore::class)
            ->call('show', 'found')
            ->assertSee('משלוח עד 5 ימי עסקים לכל הארץ')
            ->assertSee('חוזר בכ-1,840 דפי מוצר');
    }

    private function content(string $type, string $title, string $url, string $body): CatalogContent
    {
        return $this->inShop(fn (): CatalogContent => CatalogContent::query()->create([
            'type' => $type, 'external_id' => $type.'-'.md5($title), 'title' => $title, 'url' => $url, 'body' => $body, 'hash' => md5($body),
        ]));
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
