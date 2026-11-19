<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Ai\Contracts\BatchRequest;
use App\Modules\Ai\Contracts\BatchResult;
use App\Modules\Ai\Contracts\BatchState;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ActivateExpertBank;
use App\Modules\Enrichment\Actions\AuditProductTypes;
use App\Modules\Enrichment\Actions\BuildExpertBank;
use App\Modules\Enrichment\Actions\CollectProviderBatches;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\Verdict;
use App\Modules\Enrichment\Events\FactsDecided;
use App\Modules\Enrichment\Models\EnrichmentExpertBank;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentTypeAudit;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\ExpertBankShape;
use App\Modules\Enrichment\Scanning\TypeDoubt;
use App\Modules\Enrichment\Tasks\TypeAuditTask;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The type audit (Vans, 19.11): a mesh top filed under "חולצות טי ומכופתרות" and tagged with both was a T-shirt to code,
 * and showed for "חולצה מכופתרת". Code picks the few products whose type it read from the filing alone and cannot vouch
 * for, a model of the other family says which type of the bank each is or none, and code applies the verdict carefully.
 */
final class TypeAuditTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{provider: string, model: string, schema: bool, effort: ?string, input: array<string, mixed>}> every model call, in order */
    public array $calls = [];

    /** @var array<string, array<string, mixed>|callable> what the auditor answers, by the product's id in the store */
    public array $answers = [];

    public int $capAsked = 0;

    public bool $capReached = false;

    /** @var list<list<BatchRequest>> what was handed to the batch API */
    public array $submitted = [];

    public bool $batchSupported = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        Features::override(AuditProductTypes::FEATURE, true, $this->shop->id);
        Features::override(BuildExpertBank::FEATURE, true, $this->shop->id);
        Features::override('enrichment.read_text_in_code', true, $this->shop->id);
        Features::override('enrichment.batch_reading', false, $this->shop->id);

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel, StructuredChatModel
        {
            public function __construct(private readonly TypeAuditTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return $this->answer($provider, $model, $system, $user, false, $reasoningEffort);
            }

            public function structured(AiProviderName $provider, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return $this->answer($provider, $model, $system, $user, $schema !== [], $reasoningEffort);
            }

            private function answer(AiProviderName $provider, string $model, string $system, string $user, bool $schema, ?string $effort): ModelReply
            {
                $input = (array) json_decode($user, true);
                $this->test->calls[] = ['provider' => $provider->value, 'model' => $model, 'schema' => $schema, 'effort' => $effort, 'system' => $system, 'input' => $input];

                return new ModelReply($this->test->reply((string) $input['id']), 900, 60, 500);
            }
        });
        $this->app->instance(SpendGuard::class, new class($test) implements SpendGuard
        {
            public function __construct(private readonly TypeAuditTest $test) {}

            public function assertCanSpend(float $estimatedUsd, bool $forShopper = false): void
            {
                $this->test->capAsked++;
                if ($this->test->capReached) {
                    throw new SpendCapReached(30.0, $estimatedUsd, 30.0);
                }
            }

            public function spentThisMonth(): float
            {
                return 0.0;
            }

            public function cap(): float
            {
                return 100.0;
            }

            public function spentThisMonthFor(string $shopId): float
            {
                return 0.0;
            }

            public function capFor(string $shopId): float
            {
                return 30.0;
            }
        });
        $this->app->instance(BatchChatModel::class, new class($test) implements BatchChatModel
        {
            public function __construct(private readonly TypeAuditTest $test) {}

            public function supports(AiProviderName $provider): bool
            {
                return $this->test->batchSupported;
            }

            public function submit(AiProviderName $provider, string $model, array $requests): string
            {
                $this->test->submitted[] = $requests;

                return 'pb-'.count($this->test->submitted);
            }

            public function state(AiProviderName $provider, string $batchId): BatchState
            {
                return new BatchState(BatchState::ENDED, 1, 1, 0);
            }

            public function results(AiProviderName $provider, string $model, string $batchId, ?string $system = null, array $sent = []): iterable
            {
                foreach ($this->test->submitted[(int) substr($batchId, 3) - 1] as $request) {
                    $id = (string) json_decode($request->user, true)['id'];
                    yield new BatchResult($request->customId, new ModelReply($this->test->reply($id), 900, 60));
                }
            }
        });

        $this->catalogue();
    }

    /** @return array<string, mixed> */
    public function reply(string $id): array
    {
        $answer = $this->answers[$id] ?? ['type' => 'none', 'confidence' => 10, 'why' => 'not asked for'];

        return (is_callable($answer) ? $answer() : $answer) + ['id' => $id];
    }

    public function test_code_picks_only_the_doubtful_products_and_the_verdicts_are_applied_carefully(): void
    {
        $this->answers = [
            // the Vans case: a mesh top is no type of the bank
            'mesh' => ['type' => 'none', 'confidence' => 96, 'why' => 'The title and the picture say a sheer mesh top, which is no T-shirt and no button shirt.'],
            // another type of the bank, surely
            'hood' => ['type' => 'hoodie', 'confidence' => 93, 'why' => 'The title says hoodie sweatshirt.'],
            // not sure enough: nothing changes
            'vest' => ['type' => 'none', 'confidence' => 60, 'why' => 'A vest could be a layering piece.'],
        ];

        $run = app(AuditProductTypes::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        // the doubt rule: the title says the tee's type, the oxford's picture says the shirt's: neither is asked
        $this->assertEqualsCanonicalizing(['mesh', 'hood', 'vest'], array_map(fn (array $c): string => $c['input']['id'], $this->calls));
        $this->assertSame(6, $run->output['typed']);
        $this->assertSame(3, $run->output['doubtful']);
        $this->assertSame(['caption_silent' => 3, 'joined_name' => 1, 'two_types' => 1], $run->output['reasons']);

        // every call: structured, at a low effort, the other family, the whole question
        foreach ($this->calls as $call) {
            $this->assertTrue($call['schema'], 'structured output always');
            $this->assertSame('low', $call['effort']);
            $this->assertSame('openai', $call['provider']);
            $this->assertSame(['id', 'title', 'caption', 'filed', 'types', 'code_type'], array_keys($call['input']));
            $this->assertSame(['hoodie', 'shirt', 'tshirt', 'vest'], array_column($call['input']['types'], 'key'));
            $this->assertStringNotContainsString('Vans', $call['system'], 'the prompt holds no store words');
        }
        $mesh = collect($this->calls)->firstWhere('input.id', 'mesh');
        $this->assertSame('tshirt', $mesh['input']['code_type']);
        $this->assertSame(['חולצות טי ומכופתרות'], $mesh['input']['filed']['categories']);
        $this->assertSame(['חולצות טי', 'חולצות מכופתרות'], $mesh['input']['filed']['tags']);
        $this->assertStringContainsString('שקופה', (string) $mesh['input']['caption']);

        // "none" at the threshold: the code type is taken off, the product stays untyped, the claim carries the verdict
        $this->assertSame([], $this->approvedTypes('mesh'));
        $meshFact = $this->codeTypeFact('mesh');
        $this->assertSame(FactStatus::Rejected, $meshFact->status);
        $this->assertSame(Verdict::Wrong, $meshFact->review_verdict);
        $this->assertSame(TypeAuditTask::REASON_NONE, $meshFact->status_reason);
        $this->assertSame(EnrichmentTypeAudit::REMOVED, $this->audit('mesh')->status);

        // another type at the threshold: replaced by a model fact with the reason, the code claim rejected
        $this->assertSame(['hoodie'], $this->approvedTypes('hood'));
        $hood = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $this->productId('hood'))->where('kind', FactKind::Type)->where('status', FactStatus::Approved)->sole());
        $this->assertSame(FactOrigin::Model, $hood->origin);
        $this->assertSame(TypeAuditTask::REASON, $hood->status_reason);
        $this->assertSame('gpt-5.4-mini', $hood->model);
        $this->assertSame('The title says hoodie sweatshirt.', $hood->quote);
        $this->assertSame('tshirt', $hood->evidence['code_type']);
        $this->assertSame(FactStatus::Rejected, $this->codeTypeFact('hood')->status);
        $this->assertSame(EnrichmentTypeAudit::APPLIED, $this->audit('hood')->status);

        // under the threshold: nothing changes, the verdict waits for the operator
        $this->assertSame(['tshirt'], $this->approvedTypes('vest'));
        $this->assertSame(FactStatus::Approved, $this->codeTypeFact('vest')->status);
        $this->assertSame(EnrichmentTypeAudit::HELD, $this->audit('vest')->status);
        $this->assertSame(60, $this->audit('vest')->confidence);

        // the products never asked keep their types and have no verdict
        $this->assertSame(['tshirt'], $this->approvedTypes('tee'));
        $this->assertSame(['shirt'], $this->approvedTypes('oxford'));
        $this->assertSame(0, $this->inShop(fn () => EnrichmentTypeAudit::query()->whereIn('product_id', [$this->productId('tee'), $this->productId('oxford')])->count()));

        // every call accounted: the cap asked first, the ledger written, the step lines in Hebrew with the cost
        $this->assertSame(3, $this->capAsked);
        $this->assertSame(3, DB::table('ai_usage')->where('run_id', $run->id)->count());
        $this->assertGreaterThan(0, (float) DB::table('ai_usage')->where('run_id', $run->id)->sum('cost_usd'));
        $this->assertCount(2, $run->output['steps']);
        $this->assertStringContainsString('gpt-5.4-mini', $run->output['steps'][1]);
        $this->assertStringContainsString('$', $run->output['steps'][1]);
        $this->assertStringNotContainsString('$', (string) $run->summary(), 'the summary line is the merchant\'s: no model, no cost');
        $this->assertStringContainsString('3 מוצרים מוטלים בספק מתוך 6', (string) $run->summary());
    }

    public function test_the_same_question_is_never_asked_twice_and_a_stored_verdict_is_applied_again_after_a_re_reading(): void
    {
        $this->answers = ['mesh' => ['type' => 'none', 'confidence' => 96, 'why' => 'mesh'], 'hood' => ['type' => 'hoodie', 'confidence' => 93, 'why' => 'hoodie'], 'vest' => ['type' => 'none', 'confidence' => 60, 'why' => 'unsure']];
        app(AuditProductTypes::class)->handle($this->shop->id);
        $this->calls = [];

        // again, unchanged: nothing is asked and nothing changes
        $run = app(AuditProductTypes::class)->handle($this->shop->id);
        $this->assertSame([], $this->calls, 'unchanged input is never asked again');
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->output['reused'], 'the held verdict is the one doubt left: the rejected claims carry theirs');
        $this->assertSame([], $this->approvedTypes('mesh'));
        $this->assertSame(['hoodie'], $this->approvedTypes('hood'));

        // the store changes the mesh top's title: the question is new, and asked once more
        $this->inShop(fn () => CatalogProduct::query()->whereKey($this->productId('mesh'))->update(['title' => 'חולצת רשת ארוכה Ludlow Mesh II', 'hash' => 'changed']));
        $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $this->productId('mesh'))->where('kind', FactKind::Type)->update(['status' => FactStatus::Approved, 'review_verdict' => null, 'status_reason' => 'code_store_type']));
        app(AuditProductTypes::class)->handle($this->shop->id);
        $this->assertSame(['mesh'], array_map(fn (array $c): string => $c['input']['id'], $this->calls));
        $this->assertSame(2, $this->inShop(fn () => EnrichmentTypeAudit::query()->where('product_id', $this->productId('mesh'))->count()), 'one verdict a question');

        // code reads the products again from the same filing: the rejected claim is rejected again, without asking (verdicts kept)
        $this->calls = [];
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->inShop(fn () => CatalogProduct::query()->whereKey($this->productId('hood'))->update(['payload->description' => 'changed so the reading changes']));
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->assertSame([], $this->approvedTypes('mesh'));
        $this->assertSame(['hoodie'], $this->approvedTypes('hood'), 'the audited type stays through a re-reading');
        app(AuditProductTypes::class)->handle($this->shop->id);
        $this->assertSame([], $this->calls);
    }

    public function test_equal_families_fail_before_any_call(): void
    {
        Settings::set('enrichment.type_auditor_provider', 'anthropic');
        Settings::set('enrichment.type_auditor_model', 'claude-haiku-4-5');

        $run = app(AuditProductTypes::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('enrichment::runs.type_audit_same_family', $run->summary_key);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, $this->capAsked);
        $this->assertSame(['tshirt'], $this->approvedTypes('mesh'), 'nothing changed');
    }

    public function test_a_reply_that_breaks_its_schema_or_names_a_type_not_in_the_bank_is_refused_and_nothing_is_mended(): void
    {
        $this->answers = [
            'mesh' => ['type' => 'none', 'why' => 'no confidence given'],
            'hood' => ['type' => 'sweater', 'confidence' => 99, 'why' => 'a type the bank does not have'],
            'vest' => ['type' => 'none', 'confidence' => 140, 'why' => 'out of range'],
        ];

        $run = app(AuditProductTypes::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(3, $run->output['rejected']);
        $this->assertSame(0, $run->output['applied'] + $run->output['removed']);
        foreach (['mesh', 'hood', 'vest'] as $id) {
            $this->assertSame(['tshirt'], $this->approvedTypes($id), "{$id} keeps its type");
            $this->assertSame(EnrichmentTypeAudit::REJECTED, $this->audit($id)->status);
        }
        $this->assertSame('schema', $this->audit('mesh')->problems[0]);
        $this->assertSame(['unknown_type:sweater'], $this->audit('hood')->problems);
        $this->assertSame(0, $this->inShop(fn () => EnrichmentFact::query()->where('origin', FactOrigin::Model)->count()), 'no fact of a type the bank does not have');

        // a refused reply is no answer: the next run asks again
        $this->calls = [];
        $this->answers['mesh'] = ['type' => 'none', 'confidence' => 95, 'why' => 'mesh'];
        app(AuditProductTypes::class)->handle($this->shop->id);
        $this->assertContains('mesh', array_map(fn (array $c): string => $c['input']['id'], $this->calls));
        $this->assertSame([], $this->approvedTypes('mesh'));
    }

    public function test_the_spend_cap_stops_the_run_before_a_call(): void
    {
        $this->capReached = true;

        $run = app(AuditProductTypes::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('enrichment::runs.type_audit_spend_cap', $run->summary_key);
        $this->assertSame([], $this->calls);
        $this->assertSame(['tshirt'], $this->approvedTypes('mesh'));
        $this->assertSame(0, $this->inShop(fn () => EnrichmentTypeAudit::query()->count()), 'a question never sent waits for nobody');
    }

    public function test_the_questions_go_through_the_batch_api_where_the_shop_reads_in_batches_and_the_collector_applies_the_verdicts(): void
    {
        Features::override('enrichment.batch_reading', true, $this->shop->id);
        $this->batchSupported = true;
        $this->answers = ['mesh' => ['type' => 'none', 'confidence' => 96, 'why' => 'mesh'], 'hood' => ['type' => 'hoodie', 'confidence' => 93, 'why' => 'hoodie'], 'vest' => ['type' => 'none', 'confidence' => 60, 'why' => 'unsure']];
        Event::fake([FactsDecided::class]);

        $run = app(AuditProductTypes::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame('batch', $run->output['via']);
        $this->assertSame([], $this->calls, 'no call one by one');
        $this->assertSame(1, $this->capAsked, 'the whole batch asked of the cap once');
        $this->assertCount(1, $this->submitted);
        $this->assertCount(3, $this->submitted[0]);
        foreach ($this->submitted[0] as $request) {
            $this->assertNotNull($request->schema, 'structured output in the batch too');
            $this->assertSame('low', $request->effort);
        }
        $this->assertSame(EnrichmentTypeAudit::ASKED, $this->audit('mesh')->status);
        $this->assertSame(['tshirt'], $this->approvedTypes('mesh'), 'nothing changes until the answers come');

        // asked again while the batch is with the provider: nothing is sent twice
        app(AuditProductTypes::class)->handle($this->shop->id);
        $this->assertCount(1, $this->submitted);

        // the collector reads the answers through the same checks, at the batch price, and the search is told
        $collected = app(CollectProviderBatches::class)->handle($this->shop->id);
        $this->assertSame(1, $collected->output['collected']);
        $this->assertSame([], $this->approvedTypes('mesh'));
        $this->assertSame(['hoodie'], $this->approvedTypes('hood'));
        $this->assertSame(EnrichmentTypeAudit::HELD, $this->audit('vest')->status);
        $this->assertSame(3, DB::table('ai_usage')->where('run_id', $collected->id)->where('batch', true)->count());
        Event::assertDispatched(FactsDecided::class);
    }

    public function test_the_doubt_rule_in_code(): void
    {
        $bank = $this->inShop(fn () => EnrichmentVocabulary::query()->where('active', true)->sole())->definition();
        $product = $this->inShop(fn () => CatalogProduct::query()->where('external_id', 'mesh')->sole());

        // a type from the title is never doubted
        $this->assertNull(TypeDoubt::of($product, $bank, 'tshirt', 'title', null));
        // the title says the type's words: the filing only agreed
        $tee = $this->inShop(fn () => CatalogProduct::query()->where('external_id', 'tee')->sole());
        $this->assertNull(TypeDoubt::of($tee, $bank, 'tshirt', 'field', null));
        // the picture says it and the filing names one type in whole names: the type stands
        $oxford = $this->inShop(fn () => CatalogProduct::query()->where('external_id', 'oxford')->sole());
        $this->assertNull(TypeDoubt::of($oxford, $bank, 'shirt', 'field', 'חולצה מכופתרת לבנה עם כפתורים'));
        // the picture is silent: doubted
        $this->assertSame(['caption_silent'], TypeDoubt::of($oxford, $bank, 'shirt', 'field', 'חולצה לבנה')['reasons']);
        $this->assertSame(['no_caption'], TypeDoubt::of($oxford, $bank, 'shirt', 'field', null)['reasons']);
        // the picture says it, but the filing is loose: doubted for the filing
        $doubt = TypeDoubt::of($product, $bank, 'tshirt', 'store_type', 'חולצת טי אדומה');
        $this->assertSame(['two_types', 'joined_name'], $doubt['reasons']);
        $this->assertSame(['חולצות טי' => ['tshirt'], 'חולצות טי ומכופתרות' => ['tshirt'], 'חולצות מכופתרות' => ['shirt']], $doubt['named']);
        $this->assertTrue(TypeDoubt::joinsKinds('Tees & Shirts'));
        $this->assertTrue(TypeDoubt::joinsKinds('חולצות טי ומכופתרות'));
        $this->assertFalse(TypeDoubt::joinsKinds('חולצות וינטג\''));
    }

    /**
     * A store of tops with an expert bank activated (its writer Anthropic, as the demo stores' are), read in code.
     * Each product: its id in the store, its title, its store type, its category, its tags, and what its picture says.
     */
    private function catalogue(): void
    {
        $joined = $this->category('c1', 'חולצות טי ומכופתרות');
        $tees = $this->category('c2', 'חולצות טי');
        $shirts = $this->category('c3', 'חולצות מכופתרות');
        $rows = [
            // the Vans case: a mesh top under the joined category, tagged with both
            ['mesh', 'חולצת רשת ארוכה Ludlow Mesh', 'חולצות טי', $joined, ['חולצות טי', 'חולצות מכופתרות'], 'חולצת רשת ארוכה בצבע אדום עם ורוד, שקופה'],
            // a tee whose title says it: never audited
            ['tee', 'חולצת טי לוגו Classic', '', $joined, ['חולצות טי'], 'חולצת טי שחורה עם לוגו'],
            // a button shirt whose title is a model's name and whose picture says what it is: not doubted
            ['oxford', 'חולצת Oxford לבנה', '', $shirts, [], 'חולצה מכופתרת לבנה עם כפתורים'],
            // a hoodie filed with the tees
            ['hood', 'סווטשירט קפוצ\'ון Classic', '', $tees, [], 'קפוצ\'ון אפור עם כובע'],
            // a vest filed with the tees, its picture silent
            ['vest', 'וסט Core', '', $tees, [], 'בגד עליון שחור ללא שרוולים'],
            // a tee read from its category, its picture agreeing: not doubted
            ['tee2', 'Flying V Classic', '', $tees, [], 'חולצת טי לבנה עם הדפס'],
        ];
        foreach ($rows as [$id, $title, $storeType, $category, $tags, $caption]) {
            $product = $this->product($id, $title, 'מיוצר מכותנה.', [$category], ['payload' => [
                'title' => $title, 'description' => 'מיוצר מכותנה.', 'product_type' => $storeType, 'tags' => $tags,
                'categories' => [['id' => $category->external_id, 'name' => $category->name, 'path' => $category->path]], 'attributes' => [], 'meta' => [],
            ]]);
            $this->inShop(fn () => RetrievalImage::query()->create(['product_id' => $product->id, 'external_id' => $id, 'title' => $title, 'image_url' => "https://x.test/{$id}.jpg",
                'url_hash' => 'h'.$id, 'caption' => $caption, 'caption_words' => [], 'captioned_at' => now()]));
        }

        $item = fn (string $id, string $key, string $label, array $words): array => ['id' => $id, 'key' => $key, 'label' => $label, 'words' => $words];
        $bank = ['types' => [
            $item('type:tshirt', 'tshirt', 'חולצות טי', ['חולצות טי', 'חולצת טי', 'טי שירט']),
            $item('type:shirt', 'shirt', 'חולצות מכופתרות', ['חולצות מכופתרות', 'מכופתרת']),
            $item('type:hoodie', 'hoodie', 'קפוצ\'ונים', ['קפוצ\'ון', 'סווטשירט']),
            $item('type:vest', 'vest', 'וסטים', ['וסט']),
        ], 'attributes' => [
            ['id' => 'attribute:fit', 'key' => 'fit', 'label' => 'גזרה', 'multiple' => false, 'types' => ['tshirt', 'shirt'], 'values' => [
                $item('value:fit.classic', 'classic', 'קלאסית', ['classic']), $item('value:fit.slim', 'slim', 'צרה', ['slim']),
            ]],
        ]];
        $stored = $this->inShop(fn () => EnrichmentExpertBank::query()->create([
            'shop_id' => $this->shop->id, 'status' => EnrichmentExpertBank::PASSED, 'fingerprint' => str_repeat('b', 64), 'products' => 6,
            'writer' => 'anthropic / claude-opus-5-5', 'checker' => 'openai / gpt-5.4-mini',
            'bank' => ExpertBankShape::withPatterns($bank), 'gate' => ['passed' => true],
        ]));
        $this->assertNotNull(app(ActivateExpertBank::class)->activate($this->shop->id, $stored->id)['vocabulary']);
        $this->assertSame(RunStatus::Succeeded, app(ReadProductsInCode::class)->handle($this->shop->id)->status);
        // code read a type for every product, the hoodie and the vest as tees from their category (the words it had)
        foreach (['mesh' => 'tshirt', 'tee' => 'tshirt', 'oxford' => 'shirt', 'hood' => 'tshirt', 'vest' => 'tshirt', 'tee2' => 'tshirt'] as $id => $type) {
            $this->assertSame([$type], $this->approvedTypes($id), "code's type of {$id}");
        }
    }

    private function productId(string $externalId): string
    {
        return (string) $this->inShop(fn () => CatalogProduct::query()->where('external_id', $externalId)->value('id'));
    }

    /** @return list<string> */
    private function approvedTypes(string $externalId): array
    {
        return $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $this->productId($externalId))->where('kind', FactKind::Type)
            ->where('status', FactStatus::Approved)->orderBy('value_text')->pluck('value_text')->map('strval')->all());
    }

    private function codeTypeFact(string $externalId): EnrichmentFact
    {
        return $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $this->productId($externalId))->where('kind', FactKind::Type)->where('origin', FactOrigin::Code)
            ->where('status', '!=', FactStatus::Superseded)->orderByDesc('id')->firstOrFail());
    }

    private function audit(string $externalId): EnrichmentTypeAudit
    {
        return $this->inShop(fn () => EnrichmentTypeAudit::query()->where('product_id', $this->productId($externalId))->orderByDesc('id')->firstOrFail());
    }
}
