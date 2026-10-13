<?php

namespace App\Modules\Improvement\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Contracts\StoreBlog;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Improvement\Actions\DraftPost;
use App\Modules\Improvement\Filament\Operator\Pages\SiteSuggestions;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Improvement\Models\ImprovementPostWrite;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Improvement\Support\PostDocument;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A post for the store's own blog: written with what search engines read, at most the month's
 * number the operator set, built into a page by code (products linked and pictured, questions
 * and their schema), and sent to the store as a draft once the team publishes it.
 */
final class StoreBlogTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    /** @var list<array{post: array<string, mixed>, id: string|null}> what the fake blog was sent */
    public array $sent = [];

    public ?StoreFeedUnavailable $refuse = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'Rocks and Gold', 'slug' => 'rocks']);
        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly StoreBlogTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'system' => $system, 'input' => json_decode($user, true)];

                return new ModelReply(array_shift($this->test->replies) ?? [], 1000, 200);
            }
        });
        $this->app->instance(StoreBlog::class, new class($test) implements StoreBlog
        {
            public function __construct(private readonly StoreBlogTest $test) {}

            public function push(StoreConnection $connection, array $post, ?string $remoteId): array
            {
                if ($this->test->refuse !== null) {
                    throw $this->test->refuse;
                }
                $this->test->sent[] = ['post' => $post, 'id' => $remoteId];

                return ['id' => 'gid://shopify/Article/77', 'url' => null, 'admin_url' => 'https://admin.shopify.com/store/rocks/content/articles/77'];
            }
        });

        $this->inShop(function (): void {
            StoreConnection::query()->create(['shop_id' => $this->shop->id, 'platform' => 'shopify', 'site_url' => 'https://rocks.test', 'access_token' => 'lat_'.str_repeat('a', 48)]);
            foreach (['1' => 'טבעת יהלום עגול', '2' => 'טבעת אירוסין קלאסית'] as $id => $title) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://rocks.test/products/{$id}", 'image_url' => "https://cdn.test/{$id}.jpg", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'kind' => FactKind::Type, 'key' => 'type', 'value_text' => 'ring',
                    'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => "t{$id}"]);
            }
        });
    }

    public function test_a_post_is_written_for_search_engines_counted_and_stopped_at_the_months_number(): void
    {
        Settings::set('improvement.posts_per_month', 1, $this->shop->id);
        $proposal = $this->proposal('טבעת יהלום');
        $this->replies = [$this->written(), ['ok' => true, 'problems' => []]];

        $post = app(DraftPost::class)->handle($proposal);

        $this->assertNotNull($post);
        $this->assertStringContainsString('meta_description', $this->calls[0]['system'], 'the v2 prompt asks for the search fields');
        $this->assertSame(['סוג מוצר: ring'], $this->calls[0]['input']['products'][0]['facts'], 'the writer gets the products\' facts');
        $this->assertSame('טבעת יהלום: איך בוחרים', $post->seo['seo_title']);
        $this->assertSame(['טבעות', 'יהלומים'], $post->seo['tags']);
        $this->assertSame([['q' => 'איך יודעים שהיהלום אמיתי?', 'a' => 'מבקשים תעודה מגמולוג.']], $post->seo['faq'], 'a question without an answer is dropped');
        $this->assertSame('מבקשים תעודה מגמולוג.', $this->calls[1]['input']['draft']['faq'][0]['a'], 'the checker reads the questions too');
        $this->assertSame(1, $this->inShop(fn () => ImprovementPostWrite::thisMonth()));

        // The month's number is reached: no model is asked again.
        $calls = count($this->calls);
        $this->assertNull(app(DraftPost::class)->handle($this->proposal('טבעת אירוסין')));
        $this->assertCount($calls, $this->calls);
        $run = Run::query()->where('agent', DraftPost::AGENT)->latest('id')->first();
        $this->assertSame(['failed', 'improvement::runs.post_cap'], [$run->status->value, $run->summary_key]);
    }

    public function test_the_page_links_its_products_shows_their_pictures_and_its_questions_with_their_schema(): void
    {
        $post = $this->draftedPost();
        $products = $this->inShop(fn () => CatalogProduct::query()->orderBy('external_id')->get());
        $document = new PostDocument($post, $products, 'Rocks and Gold');

        $html = $document->html();

        $this->assertSame(1, substr_count($html, '<a href="https://rocks.test/products/1">טבעת יהלום עגול</a>'), 'the first mention only, linked');
        $this->assertStringNotContainsString('<h2><a', $html, 'never inside a heading');
        $this->assertStringContainsString('<img src="https://cdn.test/2.jpg" alt="טבעת אירוסין קלאסית"', $html, 'each product with its picture');
        $this->assertStringContainsString('<h2>שאלות נפוצות</h2>', $html);
        $this->assertStringContainsString('<h3>איך יודעים שהיהלום אמיתי?</h3>', $html);
        $this->assertSame('FAQPage', $document->faqSchema()['@type']);
        $this->assertSame('מבקשים תעודה מגמולוג.', $document->faqSchema()['mainEntity'][0]['acceptedAnswer']['text']);
        $this->assertSame(['url' => 'https://cdn.test/1.jpg', 'alt' => 'טבעת יהלום עגול'], $document->image());
        $this->assertSame(['title', 'html', 'excerpt', 'seo_title', 'meta_description', 'focus_keyword', 'tags', 'faq', 'image', 'author', 'post_id'], array_keys($document->payload()));
    }

    public function test_once_published_the_post_goes_to_the_store_as_a_draft_and_the_screen_says_where(): void
    {
        $post = $this->draftedPost();
        $proposal = $this->inShop(fn () => ImprovementProposal::query()->findOrFail($post->proposal_id));

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])
            ->assertSet("seo.{$proposal->id}.seo_title", 'טבעת יהלום: איך בוחרים')
            ->assertSee('איך יודעים שהיהלום אמיתי?')
            ->set("seo.{$proposal->id}.meta_description", 'כל מה שצריך לדעת לפני שקונים טבעת יהלום.')
            ->call('approve', $proposal->id);

        $this->assertCount(1, $this->sent, 'sent once, as a draft');
        $this->assertSame('כל מה שצריך לדעת לפני שקונים טבעת יהלום.', $this->sent[0]['post']['meta_description'], 'as the team left it');
        $this->assertNull($this->sent[0]['id']);
        $post = $this->inShop(fn () => $post->fresh());
        $this->assertSame(['draft', 'gid://shopify/Article/77'], [$post->remote['status'], $post->remote['id']]);
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])->assertSee('טיוטה באתר')->assertSee('https://admin.shopify.com/store/rocks/content/articles/77');

        // Sent again: the same draft, by its id; refused: the screen says why and offers to try again.
        $this->refuse = new StoreFeedUnavailable('missing_scope');
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])->call('resendPost', $proposal->id);
        $post = $this->inShop(fn () => $post->fresh());
        $this->assertSame(['failed', 'missing_scope', 'gid://shopify/Article/77'], [$post->remote['status'], $post->remote['error'], $post->remote['id']], 'the draft it made before is kept');
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])->assertSee('אשרו את ההרשאה החדשה')->assertSee('לנסות שוב');

        $this->refuse = null;
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])->call('resendPost', $proposal->id);
        $this->assertSame('gid://shopify/Article/77', $this->sent[1]['id'], 'the same draft, updated');
    }

    private function draftedPost(): ImprovementPost
    {
        $proposal = $this->proposal('טבעת יהלום');
        $written = $this->written();

        return $this->inShop(fn (): ImprovementPost => ImprovementPost::query()->create([
            'shop_id' => $this->shop->id, 'proposal_id' => $proposal->id, 'title' => $written['title'], 'body' => $written['body'], 'products' => ['1', '2'],
            'seo' => ['seo_title' => $written['seo_title'], 'meta_description' => $written['meta_description'], 'focus_keyword' => $written['focus_keyword'], 'excerpt' => $written['excerpt'], 'tags' => $written['tags'], 'faq' => [$written['faq'][0]]],
            'status' => ImprovementPost::DRAFT, 'checked' => true, 'problems' => [], 'written_by' => 'anthropic claude-sonnet-5-5', 'checked_by' => 'openai gpt-5.4-mini',
        ]));
    }

    /** @return array<string, mixed> */
    private function written(): array
    {
        return [
            'title' => 'איך בוחרים טבעת יהלום',
            'seo_title' => 'טבעת יהלום: איך בוחרים',
            'meta_description' => 'מה לבדוק בטבעת יהלום לפני שקונים: צורה, שיבוץ ותעודה.',
            'focus_keyword' => 'טבעת יהלום',
            'excerpt' => 'כל מה שצריך לדעת על טבעת יהלום.',
            'body' => "## הצורה\nטבעת יהלום עגול מחזירה הכי הרבה אור. גם טבעת יהלום עגול מתאימה ליום יום.\n\n## הקלאסיקה\nטבעת אירוסין קלאסית לא יוצאת מהאופנה.",
            'faq' => [['q' => 'איך יודעים שהיהלום אמיתי?', 'a' => 'מבקשים תעודה מגמולוג.'], ['q' => 'שאלה בלי תשובה', 'a' => '']],
            'tags' => ['טבעות', 'יהלומים', 'טבעות'],
            'products' => ['1', '2'],
        ];
    }

    private function proposal(string $topic): ImprovementProposal
    {
        return $this->inShop(function () use ($topic): ImprovementProposal {
            $review = ImprovementReview::query()->first() ?? ImprovementReview::query()->create(['shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'status' => 'reviewed', 'evidence' => [], 'digest' => '']);

            return ImprovementProposal::query()->create([
                'shop_id' => $this->shop->id, 'review_id' => $review->id, 'kind' => ImprovementProposal::CONTENT_GAP, 'title' => $topic, 'detail' => ['topic' => $topic, 'why' => 'מחפשים ואין.'],
                'evidence' => [['query' => $topic, 'searches' => 5]], 'fingerprint' => hash('sha256', $topic), 'status' => ImprovementProposal::PENDING, 'proposed_by' => 'anthropic claude-sonnet-5-5',
            ]);
        });
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
