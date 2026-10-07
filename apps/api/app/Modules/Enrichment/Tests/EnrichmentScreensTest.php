<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches\Pages\ListEnrichmentBatches;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches\Pages\ViewEnrichmentBatch;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentFacts\EnrichmentFactResource;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentFacts\Pages\ListEnrichmentFacts;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentVocabularies\Pages\ListEnrichmentVocabularies;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentVocabularies\Pages\ViewEnrichmentVocabulary;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Support\FactWriter;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

final class EnrichmentScreensTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $root = $this->category('1751', 'כלי עבודה חשמליים');
        $this->product('101', 'מסור אנכי נטען 18V גוף בלבד', "מסור אנכי נטען 18V גוף בלבד\nמשקל: 2.1 ק\"ג", [$root]);
        $this->article('900', 'איך בוחרים כלי עבודה חשמליים', 'מדריך לבחירת כלי עבודה חשמליים לבית');

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->operator = User::factory()->operator()->create();
        $this->actingAs($this->operator);
    }

    public function test_every_screen_renders_for_the_operator_in_both_languages(): void
    {
        $this->powerToolsVocabulary();
        // These screens belong to one shop, so the panel is inside one while they are read.
        $this->post('/admin/shop', ['shop' => $this->shop->id]);

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale);

            foreach (['/operator/catalog/products', '/operator/catalog/content', '/operator/enrichment/tasks', '/operator/enrichment/facts', '/operator/enrichment/vocabularies', '/operator/enrichment/rankings', '/operator/agents'] as $url) {
                $this->get($url)->assertOk();
            }
        }

        // The released template, with the place where each shop's vocabulary goes.
        $this->get('/operator/agents')->assertSee('# Rules', false)->assertSee('{{vocabulary}}', false);
    }

    public function test_merchants_cannot_open_the_agent_screens_or_download_task_files(): void
    {
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $batch = $this->batchWithOneRequest();

        $this->actingAs($merchant)->get('/operator/enrichment/tasks')->assertForbidden();
        $this->actingAs($merchant)->get(route('enrichment.batches.download', ['batch' => $batch->id]))->assertForbidden();

        auth()->logout();
        $this->get(route('enrichment.batches.download', ['batch' => $batch->id]))->assertRedirect();
    }

    public function test_the_operator_creates_a_task_file_downloads_it_and_uploads_answers(): void
    {
        app(TenantContext::class)->set($this->shop->id);
        $vocabulary = $this->powerToolsVocabulary();

        Livewire::test(ListEnrichmentBatches::class)
            ->callAction('create_task_file', data: [
                'shop_id' => $this->shop->id,
                'task' => TaskType::ProductExtraction->value,
                'vocabulary_id' => $vocabulary->id,
            ])
            ->assertHasNoActionErrors();

        $batch = EnrichmentBatch::query()->sole();
        $this->assertSame(1, $batch->request_count);

        $download = $this->get(route('enrichment.batches.download', ['batch' => $batch->id]));
        $download->assertOk()->assertHeader('Content-Type', 'application/x-ndjson; charset=utf-8');
        $lines = array_values(array_filter(explode("\n", $download->streamedContent())));
        $this->assertCount(2, $lines);
        $request = json_decode($lines[1], true);

        $answers = UploadedFile::fake()->createWithContent('answers.jsonl', json_encode([
            'custom_id' => $request['custom_id'],
            'output' => ['id' => '101', 'type' => 'jigsaw', 'specs' => ['weight_kg' => 'm2']],
        ]));

        Livewire::test(ViewEnrichmentBatch::class, ['record' => $batch->id])
            ->callAction('upload_results', data: ['model' => 'claude-haiku-4-5', 'file' => $answers])
            ->assertHasNoActionErrors();

        $this->assertSame(ItemStatus::Applied, $batch->items()->sole()->status);
        $this->assertSame(2, EnrichmentFact::query()->count());
        $this->assertDatabaseHas('runs', ['action' => 'enrichment.import_results', 'model' => 'claude-haiku-4-5', 'status' => 'succeeded']);
    }

    public function test_a_person_approves_and_rejects_facts_and_a_new_reading_keeps_their_decision(): void
    {
        app(TenantContext::class)->set($this->shop->id);
        $vocabulary = $this->powerToolsVocabulary();
        $product = app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->sole());

        $fact = EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id,
            'kind' => 'tag', 'key' => 'tag', 'value_text' => 'compact', 'quote' => 'קומפקטי',
            'origin' => 'code+model', 'status' => FactStatus::NeedsPerson, 'input_hash' => 'x',
        ]);

        Livewire::test(ListEnrichmentFacts::class)
            ->assertCanSeeTableRecords([$fact])
            ->callAction(TestAction::make('approve')->table($fact));

        $fact->refresh();
        $this->assertSame(FactStatus::Approved, $fact->status);
        $this->assertSame($this->operator->id, $fact->decided_by);

        app(FactWriter::class)->supersedePrevious('product_id', $product->id, [FactKind::Tag]);
        $this->assertSame(FactStatus::Approved, $fact->fresh()->status, 'a person decided; a new reading does not undo it');
    }

    public function test_a_screen_shows_the_shop_in_scope_and_no_other(): void
    {
        // The operator panel puts the chosen shop into the tenant scope for the whole request,
        // so a screen that was written without a thought for tenancy still shows one store.
        $tenant = app(TenantContext::class);
        $other = Shop::factory()->create(['name' => 'חנות שנייה']);

        // Deliberately not entering unscoped mode here: the whole point is that the scope bites.
        $tenant->runUnscoped(fn () => $this->powerToolsVocabulary());
        $mine = $tenant->run($this->shop->id, fn () => CatalogProduct::query()->sole());

        $write = fn (Shop $shop, ?CatalogProduct $product, string $value) => $tenant->run($shop->id, fn () => EnrichmentFact::query()->create([
            'shop_id' => $shop->id, 'product_id' => $product?->id, 'kind' => 'tag', 'key' => 'tag',
            'value_text' => $value, 'quote' => $value, 'origin' => 'code',
            'status' => FactStatus::Approved, 'input_hash' => $shop->id,
        ]));

        $write($this->shop, $mine, 'אורן');
        $write($other, null, 'אלון');

        $tenant->run($this->shop->id, fn () => Livewire::test(ListEnrichmentFacts::class)
            ->set('activeTab', 'approved')
            ->assertSee('אורן')
            ->assertDontSee('אלון'));

        $tenant->run($other->id, fn () => Livewire::test(ListEnrichmentFacts::class)
            ->set('activeTab', 'approved')
            ->assertSee('אלון')
            ->assertDontSee('אורן'));

        // Across every shop the screen is not there at all, rather than showing both stores.
        $this->assertFalse($tenant->runUnscoped(fn (): bool => EnrichmentFactResource::canAccess()));
    }

    public function test_the_facts_screen_shows_every_kind_of_fact_there_is(): void
    {
        app(TenantContext::class)->set($this->shop->id);
        $this->powerToolsVocabulary();
        $product = app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->sole());

        // One row per kind, so a kind added later cannot take the screen down with it.
        $facts = collect(FactKind::cases())->map(fn (FactKind $kind): EnrichmentFact => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'kind' => $kind,
            'key' => $kind->value,
            'value_text' => in_array($kind, [FactKind::Spec, FactKind::Flag], true) ? null : 'x',
            'value_number' => $kind === FactKind::Spec ? 1.5 : null,
            'unit' => $kind === FactKind::Spec ? 'kg' : null,
            'quote' => 'מתוך הטקסט של החנות',
            'origin' => 'code',
            'status' => FactStatus::Approved,
            'input_hash' => $kind->value,
        ]));

        Livewire::test(ListEnrichmentFacts::class)
            ->set('activeTab', 'approved')
            ->assertOk()
            ->assertSee('מתוך הטקסט של החנות');
        $this->assertCount(count(FactKind::cases()), $facts);

        // A promise the shop makes belongs to no product, and still has to render.
        $shopWide = EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'kind' => FactKind::Promise, 'key' => 'free_shipping',
            'value_text' => null, 'quote' => 'משלוח חינם לכל הארץ.', 'origin' => 'code',
            'status' => FactStatus::Approved, 'input_hash' => 'shop-wide',
        ]);

        Livewire::test(ListEnrichmentFacts::class)
            ->set('activeTab', 'approved')
            ->assertOk()
            ->assertSee('משלוח חינם לכל הארץ.');
        $this->assertNull($shopWide->product_id);
    }

    public function test_a_vocabulary_is_added_from_a_template_and_a_bad_one_is_refused_with_reasons(): void
    {
        app(TenantContext::class)->set($this->shop->id);

        Livewire::test(ListEnrichmentVocabularies::class)
            ->callAction('add_vocabulary', data: ['shop_id' => $this->shop->id, 'source' => 'template', 'template' => 'power-tools', 'author' => 'claude-opus-5'])
            ->assertHasNoActionErrors();

        $vocabulary = EnrichmentVocabulary::query()->sole();
        $this->assertSame(1, $vocabulary->version);

        Livewire::test(ViewEnrichmentVocabulary::class, ['record' => $vocabulary->id])->assertSee('מתח סוללה');

        $bad = UploadedFile::fake()->createWithContent('bad.json', json_encode(['schema_version' => 1, 'key' => 'x', 'product_types' => [['key' => 'Bad Key']]]));

        Livewire::test(ListEnrichmentVocabularies::class)
            ->callAction('add_vocabulary', data: ['shop_id' => $this->shop->id, 'source' => 'file', 'file' => $bad]);

        $this->assertSame(1, EnrichmentVocabulary::query()->count());
        $this->assertDatabaseHas('runs', ['action' => 'enrichment.import_vocabulary', 'status' => 'failed']);
    }

    private function batchWithOneRequest(): EnrichmentBatch
    {
        $vocabulary = $this->powerToolsVocabulary();

        return app(CreateTaskFile::class)->handle($this->shop->id, TaskType::ProductExtraction, $vocabulary->id)['batch'];
    }
}
