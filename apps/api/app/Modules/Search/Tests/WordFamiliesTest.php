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
                    // the checker: the notebook is no book
                    : new ModelReply(['drop' => ['f3'], 'remove' => []], 300, 20);
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
