<?php

namespace App\Modules\Taxonomy\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Contracts\ShopRules;
use App\Modules\Runs\Models\Run;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyPair;
use App\Modules\Taxonomy\Models\TaxonomyRule;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Related;
use App\Modules\Taxonomy\Support\RelatedProducts;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A rule the shop writes of its products in its own words (Aviad, 29.10: "the book 'X' does not belong with books
 * like 'Y'; it goes with the biographies"): read into a plan by a writer and a checker of another family, shown to
 * the team with the products it changes, and applied only when the team approves; then the alike by tags and the
 * search's similar products honour it.
 */
final class ShopRulesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{provider: AiProviderName, user: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> what each provider answers */
    public array $replies = [];

    private Shop $shop;

    /** @var array<string, CatalogProduct> by external id */
    private array $products = [];

    private TaxonomyTag $biography;

    private TaxonomyTag $horror;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'סלע מאיר']);
        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly ShopRulesTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'user' => (array) json_decode($user, true)];

                return new ModelReply($this->test->replies[$provider->value] ?? [], 900, 60);
            }
        });

        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach ([['1', 'מפגש עם עצמי בגיהינום'], ['2', 'דרקולה'], ['3', 'חטוף - אלי שרעבי'], ['4', 'מרד הגנרלים']] as [$id, $title]) {
                $this->products[$id] = CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'image_url' => "https://store.test/i/{$id}.jpg", 'price' => 88, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => ['רבי מכר']],
                ]);
            }
            $genre = TaxonomyGroup::query()->create(['shop_id' => $this->shop->id, 'key' => 'genre', 'label_he' => 'ז\'אנר', 'label_en' => 'Genre', 'kind' => 'inferred', 'recommend' => true, 'version' => 1]);
            $this->biography = TaxonomyTag::query()->create(['shop_id' => $this->shop->id, 'group_id' => $genre->id, 'key' => 'biography', 'label_he' => 'ביוגרפיה', 'label_en' => 'Biography', 'version' => 1]);
            $this->horror = TaxonomyTag::query()->create(['shop_id' => $this->shop->id, 'group_id' => $genre->id, 'key' => 'horror', 'label_he' => 'אימה', 'label_en' => 'Horror', 'version' => 1]);
            // the tagger read "מפגש" as horror, like "דרקולה"; "חטוף" as a biography
            foreach ([['1', $this->horror], ['2', $this->horror], ['3', $this->biography]] as [$id, $tag]) {
                TaxonomyAssignment::query()->create([
                    'shop_id' => $this->shop->id, 'subject_type' => 'product', 'subject_id' => $this->products[$id]->id, 'tag_id' => $tag->id, 'source' => 'catalog',
                    'confidence' => 0.9, 'weight' => 1.0, 'status' => 'kept', 'checked' => 'held', 'input_hash' => 'h', 'bank_version' => 1,
                ]);
            }
        });
    }

    public function test_a_rule_is_read_into_a_plan_the_team_approves_and_the_alike_honour_it(): void
    {
        $this->replies = [
            'openai' => ['understood' => 'הספר "מפגש עם עצמי בגיהינום" הוא ביוגרפיה, לא אימה, ולא יוצג כדומה ל"דרקולה".', 'link' => 'שניהם מתויגים "אימה" ו"רבי מכר".', 'question' => null, 'changes' => [
                ['id' => '1', 'add' => ['genre:biography'], 'remove' => ['genre:horror'], 'apart' => ['2'], 'alike' => ['3']],
                // not among the products the rule names: dropped by code
                ['id' => '4', 'add' => ['genre:biography'], 'remove' => [], 'apart' => [], 'alike' => []],
                // a tag the bank lacks, a pair with itself: dropped by code
                ['id' => '2', 'add' => ['genre:thriller'], 'remove' => [], 'apart' => ['2'], 'alike' => []],
            ]],
            'anthropic' => ['ok' => true, 'wrong' => [], 'why' => ''],
        ];

        $id = $this->inShop(fn (): ?string => app(ShopRules::class)->add('הספר "מפגש עם עצמי בגיהינום" לא מתאים לספרים כמו "דרקולה". הוא ביוגרפיה, כמו "חטוף".', 7));
        $this->assertNotNull($id);

        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column($this->calls, 'provider'), 'a writer, then a checker of the other family');
        $sent = $this->calls[0]['user'];
        $this->assertSame(['1', '2', '3'], array_column($sent['products'], 'id'), 'the products the rule names, by their titles in quotes; "מרד הגנרלים" is not among them');
        $this->assertSame(['genre:horror'], $sent['products'][0]['tags'], 'with the tags each has now');
        $this->assertSame([true, true, true], array_column($sent['products'], 'named'));
        $this->assertContains(['a' => '1', 'b' => '2', 'shared' => ['אימה', 'רבי מכר']], $sent['links'], 'what ties the two the rule keeps apart: the tag read by mistake, and the store\'s tag');

        $rules = $this->inShop(fn (): array => app(ShopRules::class)->list());
        $this->assertSame([TaxonomyRule::PROPOSED, 'מפגש עם עצמי בגיהינום'], [$rules[0]['status'], $rules[0]['changes'][0]['title']]);
        $this->assertSame('שניהם מתויגים "אימה" ו"רבי מכר".', $rules[0]['link']);
        $this->assertSame(['add' => ['ביוגרפיה'], 'remove' => ['אימה'], 'apart' => ['דרקולה'], 'alike' => ['חטוף - אלי שרעבי']], array_intersect_key($rules[0]['changes'][0], array_flip(['add', 'remove', 'apart', 'alike'])));
        $this->assertCount(1, $rules[0]['changes'], 'only what code gave and the rule touches');
        $this->assertSame(0, $this->inShop(fn (): int => TaxonomyPair::query()->count()), 'nothing changes before the team approves');

        $this->inShop(fn () => app(ShopRules::class)->decide($id, true, 7));

        $this->inShop(function (): void {
            $rule = TaxonomyRule::query()->sole();
            $this->assertSame([TaxonomyRule::APPLIED, 7], [$rule->status, $rule->decided_by]);
            $added = TaxonomyAssignment::query()->where('subject_id', $this->products['1']->id)->where('tag_id', $this->biography->id)->sole();
            $this->assertSame(['kept', TaxonomyAssignment::TEAM_KEPT, TaxonomyAssignment::TEAM_SOURCE], [$added->status, $added->checked, $added->source], 'the team\'s tag: kept, and never undone by tagging again');
            $removed = TaxonomyAssignment::query()->where('subject_id', $this->products['1']->id)->where('tag_id', $this->horror->id)->sole();
            $this->assertSame(['rejected', TaxonomyAssignment::TEAM_OUT], [$removed->status, $removed->checked]);
            $this->assertSame(['apart' => [(string) $this->products['2']->id], 'alike' => [(string) $this->products['3']->id]], TaxonomyPair::of((string) $this->products['1']->id));
            $this->assertSame(['apart' => [(string) $this->products['1']->id], 'alike' => []], TaxonomyPair::of((string) $this->products['2']->id), 'in both directions');

            // the alike by tags: "דרקולה" never, whatever they share; "חטוף" first
            $alike = array_column((new Related)->to((string) $this->products['1']->id, 5), 'id');
            $this->assertSame([(string) $this->products['3']->id], $alike);
        });
        $this->assertSame(['apart' => ['2'], 'alike' => ['3']], app(RelatedProducts::class)->pairs($this->shop->id, '1'), 'as the search reads them');
        $this->assertSame(1, Run::query()->where('agent', 'taxonomy.rule_reader')->count());
    }

    public function test_a_rule_that_names_nothing_or_that_the_reader_cannot_act_on_comes_back_as_a_question(): void
    {
        $this->inShop(fn () => app(ShopRules::class)->add('הכול יפה', 7));
        $this->assertSame([], $this->calls, 'nothing named: no model is asked');
        $this->assertSame([TaxonomyRule::UNCLEAR, __('taxonomy::runs.rule_nothing_named')], $this->inShop(fn (): array => [TaxonomyRule::query()->sole()->status, TaxonomyRule::query()->sole()->note]));

        $this->replies = ['openai' => ['understood' => '', 'question' => 'לאיזה ספרים לקשר את "דרקולה"?', 'changes' => []]];
        $this->inShop(fn () => app(ShopRules::class)->add('תסדרו את "דרקולה"', 7));
        $this->assertCount(1, $this->calls, 'the writer asked back: no checker');
        $this->assertSame('לאיזה ספרים לקשר את "דרקולה"?', $this->inShop(fn (): ?string => TaxonomyRule::query()->latest('id')->first()->note));
    }

    public function test_what_the_checker_finds_wrong_is_taken_out_and_the_same_rule_is_read_once(): void
    {
        $this->replies = [
            'openai' => ['understood' => 'להפריד.', 'question' => null, 'changes' => [
                ['id' => '1', 'add' => [], 'remove' => [], 'apart' => ['2'], 'alike' => []],
                ['id' => '3', 'add' => ['genre:horror'], 'remove' => [], 'apart' => [], 'alike' => []],
            ]],
            'anthropic' => ['ok' => false, 'wrong' => [['id' => '3', 'why' => 'החוק לא מדבר על "חטוף".']], 'why' => 'נוסף ספר שהחוק לא מזכיר.'],
        ];
        $text = '"מפגש עם עצמי בגיהינום" ו"דרקולה" לא דומים. "חטוף" נשאר כמו שהוא.';
        $first = $this->inShop(fn (): ?string => app(ShopRules::class)->add($text, 7));

        $rules = $this->inShop(fn (): array => app(ShopRules::class)->list());
        $this->assertSame(TaxonomyRule::PROPOSED, $rules[0]['status']);
        $this->assertSame(['מפגש עם עצמי בגיהינום'], array_column($rules[0]['changes'], 'title'), 'the change the checker found wrong is gone');

        $this->calls = [];
        $this->inShop(fn () => app(ShopRules::class)->add($text, 7));
        $this->assertSame([], $this->calls, 'the same rule against the same products is not read twice');
        $this->assertSame(TaxonomyRule::PROPOSED, $this->inShop(fn (): string => TaxonomyRule::query()->latest('id')->first()->status));

        $this->inShop(fn () => app(ShopRules::class)->decide((string) $first, false, 7));
        $this->assertSame([TaxonomyRule::REJECTED, 0], $this->inShop(fn (): array => [TaxonomyRule::query()->find($first)->status, TaxonomyPair::query()->count()]));
    }

    public function test_what_a_rule_names_with_at_is_read_exactly_and_a_post_takes_tags(): void
    {
        // Aviad, 29.10: "tree of life" was not found by its words; with "@" the team chooses from the store, and may
        // name a category, a post or a page too
        [$post, $category] = $this->inShop(function (): array {
            $category = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'bio', 'name' => 'ביוגרפיות', 'path' => ['ביוגרפיות'], 'hash' => 'b', 'product_count' => 1]);
            $this->products['3']->categories()->attach($category->id);
            $post = CatalogContent::query()->create(['shop_id' => $this->shop->id, 'type' => 'post', 'external_id' => '90', 'title' => 'למה כתבנו את חטוף', 'url' => 'https://store.test/blog/90', 'excerpt' => 'x', 'body' => 'x', 'hash' => 'p90']);

            return [$post, $category];
        });

        $found = $this->inShop(fn (): array => [app(ShopRules::class)->mentionables('מרד'), app(ShopRules::class)->mentionables('ביוג'), app(ShopRules::class)->mentionables('למה כתב')]);
        $this->assertSame(['product', '4', 'מרד הגנרלים'], [$found[0][0]['type'], $found[0][0]['id'], $found[0][0]['label']], 'the store\'s products as its search finds them');
        $this->assertContains(['type' => 'category', 'id' => 'bio', 'label' => 'ביוגרפיות', 'sub' => '1', 'image' => null], $found[1]);
        $this->assertSame(['post', '90'], [$found[2][0]['type'], $found[2][0]['id']]);

        $this->replies = [
            'openai' => ['understood' => 'להפריד ולתייג.', 'link' => '', 'question' => null, 'changes' => [
                ['id' => '4', 'add' => [], 'remove' => [], 'apart' => ['2'], 'alike' => []],
                // a post takes tags only: its pair is dropped
                ['id' => 'c:90', 'add' => ['genre:biography'], 'remove' => [], 'apart' => ['4'], 'alike' => []],
            ]],
            'anthropic' => ['ok' => true, 'wrong' => [], 'why' => ''],
        ];
        $id = $this->inShop(fn (): ?string => app(ShopRules::class)->add('@מרד הגנרלים לא דומה ל"דרקולה". @למה כתבנו את חטוף שייך ל@ביוגרפיות', 7, [
            ['type' => 'product', 'id' => '4', 'label' => 'מרד הגנרלים'],
            ['type' => 'post', 'id' => '90', 'label' => 'למה כתבנו את חטוף'],
            ['type' => 'category', 'id' => 'bio', 'label' => 'ביוגרפיות'],
            // chosen, then deleted from the text: not kept
            ['type' => 'product', 'id' => '1', 'label' => 'מפגש עם עצמי בגיהינום'],
        ]));

        $this->assertCount(3, $this->inShop(fn (): array => (array) TaxonomyRule::query()->find($id)->mentions));
        $sent = collect($this->calls[0]['user']['products'])->keyBy('id');
        $this->assertTrue($sent['4']['named'], 'named exactly by "@", whatever its words');
        $this->assertTrue($sent->has('3'), 'the category named by "@" brings its products');
        $this->assertSame('post', $sent['c:90']['kind']);

        $rules = $this->inShop(fn (): array => app(ShopRules::class)->list());
        $this->assertSame(['מרד הגנרלים', 'למה כתבנו את חטוף', 'ביוגרפיות'], $rules[0]['mentions']);
        $this->assertSame([], collect($rules[0]['changes'])->firstWhere('external_id', 'c:90')['apart'], 'a post is kept apart from nothing');

        $this->inShop(fn () => app(ShopRules::class)->decide((string) $id, true, 7));
        $this->inShop(function () use ($post): void {
            $this->assertSame(TaxonomyAssignment::TEAM_KEPT, TaxonomyAssignment::query()->where('subject_type', TaxonomyAssignment::CONTENT)->where('subject_id', $post->id)->where('tag_id', $this->biography->id)->sole()->checked);
            $this->assertSame(['apart' => [(string) $this->products['2']->id], 'alike' => []], TaxonomyPair::of((string) $this->products['4']->id));
        });
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
