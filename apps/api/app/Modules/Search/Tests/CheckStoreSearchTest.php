<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\CheckStoreSearch;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Mail\ScanCheckMail;
use App\Modules\Search\Models\SearchResolution;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * After a scan the store's search is tried as its shoppers will try it: what fails is mended for this store alone (in
 * "apply"), or only reported (in "dry"), held to a cost cap, and the team gets a report.
 */
final class CheckStoreSearchTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array{provider: AiProviderName, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        Mail::fake();
        $this->shop = Shop::factory()->create();
        $test = $this;

        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly CheckStoreSearchTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'input' => json_decode($user, true)];

                return new ModelReply(array_shift($this->test->replies) ?? [], 900, 300);
            }
        });
        // nothing near by meaning: what the words find is all there is
        $this->app->instance(SemanticSearch::class, new class implements SemanticSearch
        {
            public function similarTo(string $source, string $sourceId, array $sources, int $limit): array
            {
                return [];
            }

            public function nearText(string $shopId, string $text, array $sources, int $limit): array
            {
                return [];
            }

            public function lookAlike(string $productId, int $limit): array
            {
                return [];
            }

            public function picturesNearText(string $shopId, string $text, int $limit): array
            {
                return [];
            }

            public function picturesNearPhoto(string $shopId, string $mime, string $bytes, int $limit): array
            {
                return [];
            }

            public function picturesReady(): bool
            {
                return false;
            }

            public function ready(): bool
            {
                return true;
            }
        });

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $this->product('101', 'מדף קיר אלון', 'מדף מעץ אלון עם כיס בצד לאחסון קטן.');
            $this->product('102', 'מדף קיר אורן', 'מדף פשוט מעץ אורן.');
            $this->product('103', 'מדף פינתי לבן', 'מדף פינה צבוע.');
            $this->product('104', 'ארגז כלים', 'ארגז פלסטיק לכלי עבודה.');
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        Settings::set('search.scan_check_report_to', 'team@store.test');
    }

    public function test_what_shoppers_search_and_fails_is_tagged_from_the_store_own_descriptions_for_this_store_alone(): void
    {
        Settings::set('search.scan_check_mode', 'apply', $this->shop->id);
        $this->replies = [['searches' => [['q' => 'תושבת עם כיס בצד', 'refs' => ['p1']]], 'questions' => []]];

        $run = app(CheckStoreSearch::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertCount(1, $this->calls, 'the shoppers\' searches written once; nothing near it by meaning, so the resolver asks no model');
        $this->assertSame('מדף קיר אלון', $this->calls[0]['input']['products'][0]['title']);
        $tag = app(TenantContext::class)->run($this->shop->id, fn () => EnrichmentFact::query()->where('key', CheckStoreSearch::FEATURE_KEY)->get());
        $this->assertSame(['כיס בצד'], $tag->pluck('value_text')->all(), 'the words its own description says, on that product only');
        $this->assertSame('101', app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->find($tag[0]->product_id)->external_id));
        $this->assertSame(['101'], array_column(app(SearchCatalog::class)->handle($this->shop->id, 'כיס בצד', count: false)['groups']['product'], 'external_id'), 'found by it after the index is built again');
        $this->assertSame([], app(TenantContext::class)->run(Shop::factory()->create()->id, fn () => EnrichmentFact::query()->where('key', CheckStoreSearch::FEATURE_KEY)->pluck('id')->all()), 'no other store has it');

        Mail::assertSent(ScanCheckMail::class, fn (ScanCheckMail $mail): bool => $mail->hasTo('team@store.test') && $mail->report['mode'] === 'apply' && $mail->report['tags'] === [['tag' => 'כיס בצד', 'products' => 1]]);

        // the same catalog is not asked again
        $this->calls = [];
        app(CheckStoreSearch::class)->handle($this->shop->id);
        $this->assertSame([], $this->calls);
    }

    public function test_dry_writes_nothing_and_reports_what_it_would_have(): void
    {
        $this->replies = [['searches' => [['q' => 'תושבת עם כיס בצד', 'refs' => ['p1']]], 'questions' => []]];

        app(CheckStoreSearch::class)->handle($this->shop->id);

        $this->assertSame(0, app(TenantContext::class)->run($this->shop->id, fn () => EnrichmentFact::query()->where('key', CheckStoreSearch::FEATURE_KEY)->count()));
        $this->assertSame(0, app(TenantContext::class)->run($this->shop->id, fn () => SearchResolution::query()->count()));
        Mail::assertSent(ScanCheckMail::class, fn (ScanCheckMail $mail): bool => $mail->report['mode'] === 'dry' && $mail->report['tags'] !== []);
    }

    public function test_with_no_budget_no_model_is_asked_and_code_still_tries(): void
    {
        Settings::set('search.scan_check_usd', 0, $this->shop->id);

        $run = app(CheckStoreSearch::class)->handle($this->shop->id);

        $this->assertSame([], $this->calls);
        $this->assertGreaterThan(0, (int) $run->output['tried'], 'the store\'s own kinds and names are tried, free');
    }

    private function product(string $id, string $title, string $description): void
    {
        CatalogProduct::query()->create([
            'shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
            'url' => "https://www.store.test/p/{$id}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}",
            'payload' => ['description' => $description],
        ]);
    }
}
