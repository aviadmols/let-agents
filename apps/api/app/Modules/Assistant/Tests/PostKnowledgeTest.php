<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Actions\ReadPostKnowledge;
use App\Modules\Assistant\Filament\Merchant\Pages\AboutStore;
use App\Modules\Assistant\Jobs\ReadPostKnowledgeJob;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What the store's posts say about the store itself, read at night by one family and checked by
 * the other, kept with what the store says about itself, and read by the assistant. As Gueta
 * Avigdor's article on its cutting calculator.
 */
final class PostKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{provider: AiProviderName, model: string, system: string, user: string}> */
    public array $calls = [];

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'גואטה אביגדור']);
        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly PostKnowledgeTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = compact('provider', 'model', 'system', 'user');
                $input = json_decode($user, true);

                // The checker keeps what the post states; the reader writes from the post.
                if (isset($input['facts'])) {
                    return new ModelReply(['keep' => array_values(array_map(fn (array $f): string => $f['id'], array_filter($input['facts'], fn (array $f): bool => ! str_contains($f['text'], 'הכי טובה בארץ'))))], 900, 20);
                }

                return new ModelReply(['facts' => str_contains($input['title'], 'חיתוך') ? [
                    'באתר יש מחשבון חיתוך שמחשב את החלקים הרצויים בפלטות ובעצים.',
                    'את מחשבון החיתוך מוצאים מתחת לכפתור ההוספה לסל.',
                    'החיתוך עולה 5 ₪ לחתך.',
                    'החנות הכי טובה בארץ לחיתוך.',
                ] : []], 1200, 80);
            }
        });
    }

    public function test_what_a_post_says_about_the_store_is_read_checked_and_answered_from(): void
    {
        $cutting = $this->article('מחשבון חיתוך: איך מזמינים פלטות חתוכות', '<p>באתר שלנו יש מחשבון חיתוך ייחודי. הוא מתחת לכפתור ההוספה לסל.</p>');
        $this->article('איך בוחרים עץ לפרגולה', '<p>אורן מחוטא מתאים לחוץ.</p>');

        $run = app(ReadPostKnowledge::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value);
        // Two posts read, one checked: the guide on pine said nothing about the store.
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic, AiProviderName::OpenAi], array_column($this->calls, 'provider'), 'the other family checks');
        $this->assertSame(file_get_contents(__DIR__.'/../Prompts/post_facts.v2.md'), $this->calls[0]['system']);
        $this->assertCount(3, json_decode($this->calls[1]['user'], true)['facts'], 'code drops the sum before the checker sees it');

        $body = (string) $this->entry()->body;
        $this->assertStringContainsString('- את מחשבון החיתוך מוצאים מתחת לכפתור ההוספה לסל. (מחשבון חיתוך: איך מזמינים פלטות חתוכות)', $body);
        $this->assertStringNotContainsString('₪', $body, 'a sum is never told');
        $this->assertStringNotContainsString('הכי טובה', $body, 'the checker keeps only what the post states');

        // Never asked twice: nothing changed, no model.
        $this->calls = [];
        app(ReadPostKnowledge::class)->handle($this->shop->id);
        $this->assertSame([], $this->calls);
        $this->assertSame($body, (string) $this->entry()->body);

        // The assistant reads the lines that speak of the question, as the store's knowledge.
        $passages = $this->inShop(fn (): array => (new ReflectionMethod(AnswerSiteQuestion::class, 'knowledge'))
            ->invoke(app(AnswerSiteQuestion::class), 'יש לכם מחשבון חיתוך?', $this->shop->id, []));
        $this->assertCount(1, $passages);
        $this->assertStringContainsString('מתחת לכפתור ההוספה לסל', $passages[0]['text']);
        $this->assertTrue($passages[0]['speaks']);
        $this->assertSame([], $this->inShop(fn (): array => (new ReflectionMethod(AnswerSiteQuestion::class, 'knowledge'))
            ->invoke(app(AnswerSiteQuestion::class), 'מה שעות הפתיחה?', $this->shop->id, [])), 'nothing from the posts speaks of it');

        // The team removes a line: gone now, and after the post changes and is read again.
        $this->inShop(fn () => app(BuildShopKnowledge::class)->hidePostFact('את מחשבון החיתוך מוצאים מתחת לכפתור ההוספה לסל.'));
        $this->inShop(fn () => $cutting->forceFill(['body' => '<p>באתר שלנו יש מחשבון חיתוך. הוא מתחת לכפתור ההוספה לסל, ועכשיו גם בעמוד הבית.</p>', 'hash' => 'changed'])->save());
        $this->calls = [];
        app(ReadPostKnowledge::class)->handle($this->shop->id);
        $this->assertCount(2, $this->calls, 'only the changed post is read');
        $this->assertStringNotContainsString('מוצאים מתחת לכפתור', (string) $this->entry()->body);
        $this->assertStringContainsString('מחשבון חיתוך שמחשב', (string) $this->entry()->body);
    }

    public function test_a_checker_of_the_same_family_fails_the_run_and_no_model_is_asked(): void
    {
        $this->article('מחשבון חיתוך', '<p>יש מחשבון.</p>');
        Settings::set('assistant.post_check_provider', 'openai');

        $run = app(ReadPostKnowledge::class)->handle($this->shop->id);

        $this->assertSame('failed', $run->status->value);
        $this->assertSame([], $this->calls);
    }

    public function test_the_stores_policies_are_read_first_then_its_pages_then_its_posts(): void
    {
        $this->article('מחשבון חיתוך', '<p>יש מחשבון חיתוך באתר.</p>');
        $this->inShop(fn () => CatalogContent::query()->create(['type' => CatalogContent::PAGE, 'external_id' => '100', 'title' => 'אודות', 'body' => '<p>חנות משפחתית.</p>', 'hash' => 'a']));
        $this->inShop(fn () => CatalogContent::query()->create(['type' => CatalogContent::PAGE, 'external_id' => 'policy-9', 'title' => 'מדיניות החזרות', 'body' => '<p>אפשר להחזיר תוך 14 יום.</p>', 'hash' => 'b']));
        Settings::set('assistant.post_knowledge_per_run', 1, $this->shop->id);

        app(ReadPostKnowledge::class)->handle($this->shop->id);

        $first = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['policy', 'מדיניות החזרות'], [$first['kind'], $first['title']], 'one a night: the returns policy first');

        $this->calls = [];
        app(ReadPostKnowledge::class)->handle($this->shop->id);
        $second = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['page', 'אודות'], [$second['kind'], $second['title']], 'then a page, before the post');
    }

    public function test_only_a_few_posts_a_night_and_the_rest_wait(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->article("חיתוך {$n}", "<p>מחשבון חיתוך {$n}.</p>");
        }
        Settings::set('assistant.post_knowledge_per_run', 2, $this->shop->id);

        app(ReadPostKnowledge::class)->handle($this->shop->id);
        $this->assertCount(2, (array) $this->entry()->terms['read']);

        app(ReadPostKnowledge::class)->handle($this->shop->id);
        $this->assertCount(3, (array) $this->entry()->terms['read'], 'the next night reads the one that waited');
    }

    public function test_the_merchant_sees_what_the_posts_taught_removes_a_line_and_asks_to_read_again(): void
    {
        $this->article('מחשבון חיתוך: איך מזמינים פלטות חתוכות', '<p>יש מחשבון חיתוך מתחת לכפתור ההוספה לסל.</p>');
        app(ReadPostKnowledge::class)->handle($this->shop->id);
        Queue::fake();

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);

        Livewire::test(AboutStore::class)
            ->assertOk()
            ->call('show', 'found')
            ->assertSee('את מחשבון החיתוך מוצאים מתחת לכפתור ההוספה לסל.')
            ->assertSee('מהפוסט')
            ->assertDontSee('gpt-')
            ->call('removePostFact', 'את מחשבון החיתוך מוצאים מתחת לכפתור ההוספה לסל.')
            ->assertDontSee('את מחשבון החיתוך מוצאים מתחת לכפתור ההוספה לסל.')
            ->call('readPosts');

        Queue::assertPushed(ReadPostKnowledgeJob::class, fn (ReadPostKnowledgeJob $job): bool => $job->shopId === $this->shop->id);
    }

    private function article(string $title, string $body): CatalogContent
    {
        return $this->inShop(fn (): CatalogContent => CatalogContent::query()->create([
            'type' => CatalogContent::POST, 'external_id' => 'post-'.md5($title), 'title' => $title, 'body' => $body, 'hash' => md5($body),
        ]));
    }

    private function entry(): CatalogContent
    {
        return $this->inShop(fn (): CatalogContent => app(BuildShopKnowledge::class)->entries()[BuildShopKnowledge::POSTS]);
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
