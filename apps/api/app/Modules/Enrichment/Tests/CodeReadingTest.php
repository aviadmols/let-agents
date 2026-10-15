<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\ImportTaskResults;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\BrandResolver;
use App\Modules\Enrichment\Scanning\ContentDigest;
use App\Modules\Enrichment\Scanning\PatternMatch;
use App\Modules\Enrichment\Scanning\ProductFamily;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Enums\RunStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The code-only pass: what it settles on its own, what it leaves to a model, and how a model
 * reading respects it. Titles and texts are the pilot store's.
 */
final class CodeReadingTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_brands_come_from_store_fields_titles_and_makers_named_in_descriptions(): void
    {
        $this->buildShop();
        $resolver = new BrandResolver;

        $field = $this->make(['brand' => 'MAKITA', 'title' => 'סוללה 18V 4Ah']);
        $this->assertSame(['brand' => 'Makita', 'source' => 'store_field', 'quote' => 'MAKITA'], $resolver->resolve($field));

        $attribute = $this->make(['title' => 'מנקה עץ', 'payload' => ['attributes' => [['name' => 'מותג', 'values' => ['Flood']]]]]);
        $this->assertSame('Flood', $resolver->resolve($attribute)['brand']);

        $title = $this->make(['title' => 'דק גארד של BONDEX צבע מגן שקוף לדק']);
        $this->assertSame(['Bondex', 'title'], [$resolver->resolve($title)['brand'], $resolver->resolve($title)['source']]);

        $hebrew = $this->make(['title' => 'מתאם לסוללת מקיטה 18 וולט']);
        $this->assertSame('Makita', $resolver->resolve($hebrew)['brand'], 'a Hebrew spelling with a prefix letter');

        $maker = $this->make(['title' => 'פנס לד 18V ללא סוללה ומטען (גוף בלבד)', 'payload' => ['short_description' => 'מבחר ענק של כלי עבודה חשמליים מבית המותג הבינלאומי - סטנלי להזמנה אונליין']]);
        $this->assertSame(['Stanley', 'short_description'], [$resolver->resolve($maker)['brand'], $resolver->resolve($maker)['source']]);

        $mentioned = $this->make(['title' => 'פלטת לאמי אלון', 'payload' => ['description' => 'פלטה מעץ אלון מלא. מומלץ לטפל בשמן בלנשון לפני השימוש.']]);
        $this->assertNull($resolver->resolve($mentioned), 'a brand recommended in the text is not the maker');

        $unknown = $this->make(['title' => 'ספריי להסרת עובש וטחב מבית SAG ליטר 1']);
        $this->assertSame(['SAG', 'title_maker'], [$resolver->resolve($unknown)['brand'], $resolver->resolve($unknown)['source']]);

        $this->assertNull($resolver->resolve($this->make(['title' => 'עץ אורן מוקצע בפרופיל 20X45 מ"מ (2X5)'])));
    }

    public function test_a_family_is_the_title_without_sizes_in_the_same_category(): void
    {
        $this->buildShop();
        $beams = $this->category('2336', 'דו שכבתי', '2339', ['עצים', 'עץ אורן', 'מוקצע לא מחוטא', 'דו שכבתי']);
        $other = $this->category('2755', 'תלת/רב שכבתי', '2339', ['עצים', 'עץ אורן', 'מוקצע לא מחוטא', 'תלת/רב שכבתי']);

        $a = ProductFamily::of($this->product('12004', 'עץ אורן דו שכבתי 85X85 מ"מ (10X10)', 'x', [$beams]));
        $b = ProductFamily::of($this->product('12007', 'עץ אורן דו שכבתי 85X135 מ"מ (10X15)', 'x', [$beams]));
        $c = ProductFamily::of($this->product('22866', 'עץ אורן תלת/רב שכבתי 85X235 מ"מ (10X25)', 'x', [$other]));
        $battery = ProductFamily::of($this->make(['title' => 'סוללה 18V 5Ah Makita BL1850B – ביצועים מקסימליים לעבודה ממושכת']));

        $this->assertSame($a['key'], $b['key']);
        $this->assertSame('עץ אורן דו שכבתי', $a['name']);
        $this->assertNotSame($a['key'], $c['key']);
        $this->assertSame('סוללה Makita', $battery['name'], 'numbers, model codes and the marketing tail are left out');
    }

    public function test_code_settles_type_brand_and_title_sizes_and_the_model_answers_only_the_rest(): void
    {
        $this->buildShop();
        $wood = $this->category('2212', 'עצים');
        $pine = $this->category('2213', 'עץ אורן', '2212', ['עצים', 'עץ אורן']);
        $planed = $this->category('2339', 'מוקצע לא מחוטא', '2213', ['עצים', 'עץ אורן', 'מוקצע לא מחוטא']);

        $board = $this->product('11532', 'עץ אורן מוקצע בפרופיל 20X45 מ"מ (2X5)', 'עץ אורן איכותי', [$wood, $pine, $planed], [
            'payload' => ['meta' => ['price_text' => 'מחיר למטר'], 'attributes' => [['name' => 'אורך', 'values' => ['3 מטר', '3.30 מטר'], 'used_for_variations' => true]],
                'categories' => [['id' => '2212', 'path' => ['עצים']], ['id' => '2213', 'path' => ['עצים', 'עץ אורן']], ['id' => '2339', 'path' => ['עצים', 'עץ אורן', 'מוקצע לא מחוטא']]]],
        ]);
        $corner = $this->product('13760', 'זוית עץ אורן 45×45 מ״מ אורך 2.40 מטר', 'x', [$wood, $pine, $planed], [
            'payload' => ['categories' => [['id' => '2339', 'path' => ['עצים', 'עץ אורן', 'מוקצע לא מחוטא']]]],
        ]);

        $data = ImportVocabulary::template('wood');
        [$definition, $problems] = VocabularyDefinition::parse($data);
        $this->assertSame([], $problems);
        $this->assertSame(['thickness_mm' => ['value' => 20.0, 'unit' => 'mm', 'quote' => '20X45 מ"מ'], 'width_mm' => ['value' => 45.0, 'unit' => 'mm', 'quote' => '20X45 מ"מ']], $definition->titleSpecs($board->title, 'planed_board'));
        $this->assertSame([45.0, 45.0], array_column($definition->titleSpecs($corner->title, 'planed_board'), 'value'), 'two numbers alike in two places: two measures');

        // One number read by several measures' patterns is none of them ("3*3.60": 3 cm long, wide and high).
        $loose = $data;
        $loose['attributes'] = array_values(array_filter($loose['attributes'], fn (array $a): bool => ($a['type'] ?? 'number') !== 'number'));
        foreach (['length_mm', 'width_mm', 'height_mm'] as $key) {
            $loose['attributes'][] = ['key' => $key, 'type' => 'number', 'dimension' => 'length', 'min' => 1, 'max' => 10000, 'label' => ['he' => $key, 'en' => $key],
                'title_capture' => ['pattern' => '(\d+(?:\.\d+)?)\s*\*', 'group' => 1, 'factor' => 10]];
        }
        [$looseDefinition] = VocabularyDefinition::parse($loose);
        $this->assertSame([], $looseDefinition->titleSpecs('קיט פרגולה 3*3.60 מטר', null));
        app(ImportVocabulary::class)->handle($this->shop->id, $data, 'test');

        $run = app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);

        $reading = $this->inShop(fn () => EnrichmentCodeReading::query()->where('product_id', $board->id)->sole()->reading);
        $this->assertSame(['key' => 'planed_board', 'source' => 'category'], $reading['type']);
        $this->assertSame('מחיר למטר', $reading['price_unit']);
        $this->assertSame([['name' => 'אורך', 'values' => ['3 מטר', '3.30 מטר']]], $reading['choices']);

        $facts = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $board->id)->get());
        $this->assertTrue($facts->every(fn (EnrichmentFact $f): bool => $f->origin === FactOrigin::Code && $f->status === FactStatus::Approved));
        $this->assertEqualsCanonicalizing(['type', 'thickness_mm', 'width_mm', 'species', 'treatment'], $facts->pluck('key')->all());
        $this->assertSame(['species' => 'pine', 'treatment' => 'untreated'], $reading['category_choices'], 'the category says untreated pine even though the title does not');

        $cornerReading = $this->inShop(fn () => EnrichmentCodeReading::query()->where('product_id', $corner->id)->sole()->reading);
        $this->assertArrayNotHasKey('type', $cornerReading, 'the title says trim, not a planed board: code leaves the type to the model');

        // Running again with nothing changed writes nothing.
        $again = app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->assertSame(0, $again->output['changed']);

        // The model is told what code knows, cannot change the type, and every job goes to review.
        $vocabulary = $this->inShop(fn () => EnrichmentVocabulary::query()->where('key', 'wood')->sole());
        $batch = app(CreateTaskFile::class)->handle($this->shop->id, TaskType::ProductExtraction, $vocabulary->id)['batch'];
        $item = $this->inShop(fn () => $batch->items()->where('subject_id', $board->id)->sole());

        $this->assertSame(['type' => 'planed_board', 'choices' => ['species' => 'pine', 'treatment' => 'untreated'], 'specs' => ['thickness_mm' => [20, 'mm'], 'width_mm' => [45, 'mm']]], $item->request['known']);
        $this->assertTrue(collect($item->request['candidates'])->contains(fn (array $c): bool => $c[1] === 'use' && $c[2] === 'pergola' && $c[3] === ''), 'a job usually done with planed boards is offered to accept or reject');
        $this->assertStringContainsString('`pergola`', $batch->system_prompt);

        $contents = json_encode(['type' => 'header', 'batch_id' => $batch->id])."\n".json_encode(['custom_id' => $item->custom_id, 'output' => [
            'id' => '11532', 'type' => 'trim', 'specs' => [], 'yes' => [], 'uses' => ['pergola', 'deck', 'stairs', 'no_such_job'], 'add' => [],
        ]]);
        app(ImportTaskResults::class)->handle($this->inShop(fn () => $batch->fresh()), $contents, 'claude-haiku-4-5');

        $item = $this->inShop(fn () => $item->fresh());
        $this->assertContains('type_differs_from_code:trim', $item->problems);
        $this->assertContains('use_not_for_type:stairs', $item->problems);
        $this->assertContains('unknown_use:no_such_job', $item->problems);

        $current = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $board->id)->where('status', '!=', FactStatus::Superseded)->get());
        $this->assertSame('planed_board', $current->firstWhere('kind', FactKind::Type)->value_text, 'code facts survive a model reading');
        $uses = $current->where('kind', FactKind::Use);
        $this->assertEqualsCanonicalizing(['pergola', 'deck'], $uses->pluck('value_text')->all());
        $this->assertTrue($uses->every(fn (EnrichmentFact $f): bool => $f->status === FactStatus::AwaitingReview));
    }

    public function test_a_product_filed_in_two_branches_goes_to_the_vocabulary_whose_category_says_what_it_is(): void
    {
        $this->buildShop();
        $wood = $this->category('2212', 'עצים');
        $shelves = $this->category('2676', 'מידוף', '1747', ['מוצרי פרזול', 'מידוף']);
        $hardware = $this->category('1747', 'מוצרי פרזול');
        $supports = $this->category('2834', 'תומכי מדף', '1747', ['מוצרי פרזול', 'תומכי מדף']);

        // The pine shelf sits in wood and in hardware's shelving; the support only in hardware.
        $shelf = $this->product('19949', 'מדף מעץ אורן בעובי 18 מ"מ', 'x', [$wood, $hardware, $shelves], [
            'payload' => ['categories' => [['id' => '2212', 'path' => ['עצים']], ['id' => '1747', 'path' => ['מוצרי פרזול']], ['id' => '2676', 'path' => ['מוצרי פרזול', 'מידוף']]]],
        ]);
        $support = $this->product('27803', 'תומך מדף גלים דגם BT020', 'x', [$hardware, $supports], [
            'payload' => ['categories' => [['id' => '1747', 'path' => ['מוצרי פרזול']], ['id' => '2834', 'path' => ['מוצרי פרזול', 'תומכי מדף']]]],
        ]);

        // "hardware" sorts before "wood": without the rule, the shelf would go to hardware and lose its type.
        app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('hardware'), 'test');
        app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('wood'), 'test');
        $run = app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);

        $readings = $this->inShop(fn () => EnrichmentCodeReading::query()->get()->keyBy('product_id'));
        $this->assertSame(['wood', 'shelf'], [$readings[$shelf->id]->reading['vocabulary'], $readings[$shelf->id]->reading['type']['key']]);
        $this->assertSame(['hardware', 'shelf_support'], [$readings[$support->id]->reading['vocabulary'], $readings[$support->id]->reading['type']['key']]);
        $this->assertSame(1, $run->output['products_in_two_branches']);
    }

    public function test_article_categories_match_construct_state_forms(): void
    {
        $this->buildShop();
        $care = $this->category('1750', 'תחזוקה, ניקיון ושמנים');
        $wood = $this->category('2212', 'עצים');

        $matched = ContentDigest::matchCategories('מוצרי תחזוקת עץ', 'שמן לדק ושמן לרהיטים', $this->inShop(fn () => collect([$care, $wood])));

        $this->assertContains('1750', array_map(fn ($c) => $c->external_id, $matched), '"תחזוקת" is "תחזוקה" in construct state');
    }

    public function test_the_scan_log_shows_one_products_whole_story(): void
    {
        $this->buildShop();
        $tools = $this->category('1751', 'כלי עבודה חשמליים');
        $product = $this->product('9236', 'גוף מברגה/מקדחה רוטטת ונטענת Makita', 'x', [$tools], ['brand' => 'Makita']);
        app(ReadProductsInCode::class)->handle($this->shop->id);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        // A shop-owned screen opens once the panel is inside a shop, the way the picker sets it.
        $this->post('/admin/shop', ['shop' => $this->shop->id]);

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->get('/operator/enrichment/scan-log?product='.$product->id)
                ->assertOk()
                ->assertSee('גוף מברגה/מקדחה רוטטת ונטענת Makita')
                ->assertSee('store_field');

            $this->withHeader('Accept-Language', $locale)->get('/operator/enrichment/relations')->assertOk();
            $this->withHeader('Accept-Language', $locale)->get('/operator/enrichment/vocabularies')->assertOk();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function make(array $attributes): CatalogProduct
    {
        return new CatalogProduct(array_replace(['title' => 'x', 'payload' => []], $attributes));
    }

    public function test_heat_is_not_brown(): void
    {
        foreach (['מוריד את החום בדירה כ-20%', 'עמיד בחום ובקור', 'בידוד חום מעולה', '20% מוריד חום', 'עמיד לחום עד 80 מעלות'] as $heat) {
            $this->assertNull(PatternMatch::first($heat, ['חום']), $heat);
        }
        foreach (['שולחן בצבע חום', 'ארון חום כהה', 'כיסא חום'] as $brown) {
            $this->assertNotNull(PatternMatch::first($brown, ['חום']), $brown);
        }
    }
}
