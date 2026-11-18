<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\CompleteVocabulary;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\BankGaps;
use App\Modules\Enrichment\Scanning\BrandResolver;
use App\Modules\Enrichment\Scanning\PictureText;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Models\RetrievalImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bank completion (Me369, 18.11): a bank written from a sample left a fashion store's scarves with no type and had no
 * "sleeveless". What every product says is read in code; the writer proposes only for those gaps; code keeps what tags
 * products and merges a value that is another's twin; the auditor of the other family drops what tags wrongly; nothing
 * is active until asked.
 */
final class BankCompletionTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<string> the system prompts asked, in order */
    public array $asked = [];

    /** @var array<string, mixed>|null what the writer proposes, when a test sets it */
    public ?array $proposal = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        $label = fn (string $he, string $en): array => ['he' => $he, 'en' => $en];
        app(ImportVocabulary::class)->handle($this->shop->id, [
            'schema_version' => 1, 'key' => 'fashion', 'name' => $label('אופנה', 'Fashion'), 'root_category_external_id' => '*',
            'product_types' => [['key' => 'dress', 'label' => $label('שמלה', 'Dress'), 'patterns' => ['\bdress\b']]],
            'attributes' => [['key' => 'sleeve_type', 'type' => 'enum', 'label' => $label('שרוולים', 'Sleeves'), 'values' => [
                ['key' => 'long_sleeve', 'label' => $label('ארוכים', 'Long sleeve'), 'patterns' => ['\blong sleeves?\b']],
                ['key' => 'short_sleeve', 'label' => $label('קצרים', 'Short sleeve'), 'patterns' => ['\bshort sleeves?\b']],
            ]]],
        ]);

        foreach ([
            '1' => ['Martha Camisole Dress', ['maxi dress', 'sleeveless', 'thin straps']],
            '2' => ['Olivia Slip Dress', ['dress', 'sleeveless', 'spaghetti straps']],
            '3' => ['Allison Sleeveless Dress', ['dress', 'sleeveless']],
            '4' => ['Gwen Belted Dress', ['dress', 'long sleeves']],
            '5' => ['Sky Silk Scarf', ['scarf', 'silk']],
            '6' => ['River Linen Scarf', ['scarf', 'linen']],
        ] as $id => [$title, $words]) {
            $product = $this->product($id, $title, '', []);
            $this->inShop(fn () => RetrievalImage::query()->create(['product_id' => $product->id, 'external_id' => $id, 'title' => $title, 'image_url' => "https://x.test/{$id}.jpg",
                'url_hash' => 'h'.$id, 'caption' => $title.'.', 'caption_words' => $words, 'captioned_at' => now()]));
        }

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly BankCompletionTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->asked[] = str_contains($system, 'You complete') ? 'writer' : (str_contains($system, 'were missed') ? 'recall' : 'auditor');
                if (str_contains($system, 'were missed')) {
                    return new ModelReply(['verdicts' => array_map(fn (array $i): array => ['id' => $i['id'], 'value' => 'none'], (array) (json_decode($user, true)['items'] ?? []))], 1000, 100);
                }
                if (str_contains($system, 'You complete') && $this->test->proposal !== null) {
                    return new ModelReply($this->test->proposal, 3000, 800);
                }
                if (str_contains($system, 'You complete')) {
                    $label = fn (string $en): array => ['label_he' => $en, 'label_en' => $en];

                    return new ModelReply(['types' => [['key' => 'scarf', 'patterns' => ['\bscarf\b'], 'same_as' => '', 'groups' => ['scarf']] + $label('Scarf')],
                        'values' => [
                            ['attribute' => 'sleeve_type', 'key' => 'sleeveless', 'patterns' => ['\bsleeveless\b'], 'same_as' => ''] + $label('Sleeveless'),
                            // a wrong value: "straps" is no sleeve type of its own
                            ['attribute' => 'sleeve_type', 'key' => 'strappy', 'patterns' => ['\bthin straps\b', '\bspaghetti straps\b'], 'same_as' => ''] + $label('Strappy'),
                        ],
                        'attributes' => []], 3000, 800);
                }
                $items = (array) (json_decode($user, true)['items'] ?? []);

                return new ModelReply(['verdicts' => array_map(fn (array $i): array => ['id' => $i['id'], 'verdict' => $i['tag']['value'] === 'strappy' ? 'incorrect' : 'correct'], $items)], 2000, 300);
            }
        });
    }

    public function test_every_product_is_read_for_what_the_bank_does_not_cover_and_the_stores_own_data_counts_alone(): void
    {
        $gaps = $this->inShop(function (): array {
            $products = CatalogProduct::query()->with('variants')->get();
            $vocabulary = EnrichmentVocabulary::query()->where('active', true)->sole()->definition();

            return BankGaps::find($vocabulary, $products, PictureText::for(), BrandResolver::forCatalog($products));
        });

        $this->assertSame(['scarf'], array_column($gaps['untyped'], 'group'), 'the scarves, grouped by the last word of their title');
        $this->assertSame(2, $gaps['untyped'][0]['products']);
        $dressPhrases = array_column($gaps['phrases']['dress'] ?? [], 'products', 'phrase');
        $this->assertSame(3, $dressPhrases['sleeveless'] ?? null, 'said by three dresses, read by no value');
        $this->assertArrayNotHasKey('long sleeves', $dressPhrases, 'what a value reads already is no gap');
        $this->assertArrayNotHasKey('thin straps', $dressPhrases, 'one picture alone is no gap');
    }

    public function test_the_completed_bank_types_the_untyped_adds_what_holds_drops_what_tags_wrongly_and_waits_to_be_activated(): void
    {
        app(CompleteVocabulary::class)->handle($this->shop->id, false, $report);

        $this->assertSame(['writer', 'auditor', 'recall'], $this->asked, 'one request to write, one to audit, one to look for missed tags');
        $this->assertSame(2, $report['missed']['sleeve_type']['without'] ?? null, 'the scarves (sleeves apply to every type here): read, and found to have none');
        $this->assertSame(0, $report['missed']['sleeve_type']['estimated_missed'] ?? null);
        $this->assertSame(['type scarf', 'sleeve_type.sleeveless'], array_column($report['added'], 'label'));
        $this->assertContains('sleeve_type.strappy', array_column($report['rejected'], 'item'), 'the auditor found what it tags wrong');
        $this->assertSame(2, $report['untyped_before']);
        $this->assertSame(0, $report['untyped_after'], 'every scarf has a type now');
        $this->assertSame(3, $report['changes']['by_attribute']['sleeve_type']['gained'] ?? null, 'three dresses with a sleeve type they had none of');
        $this->assertSame('ready', $report['outcome']);
        $this->assertSame(1, $this->inShop(fn () => EnrichmentVocabulary::query()->count()), 'the active bank is untouched until asked');
        $this->assertNotEmpty($report['costs']);

        app(CompleteVocabulary::class)->handle($this->shop->id, true, $report);
        $active = $this->inShop(fn () => EnrichmentVocabulary::query()->where('active', true)->sole());
        $this->assertSame(2, $active->version);
        $this->assertContains('scarf', array_column($active->definition()->productTypes(), 'key'));
        $this->assertSame(['long_sleeve', 'short_sleeve', 'sleeveless'], array_column($active->definition()->attribute('sleeve_type')['values'], 'key'));
    }

    public function test_a_new_value_never_changes_what_a_product_had_nor_a_new_attribute_repeats_one(): void
    {
        $label = fn (string $en): array => ['label_he' => $en, 'label_en' => $en];
        $this->proposal = ['types' => [], 'values' => [
            // the title says "Belted": read before the picture's "long sleeves", it would turn Gwen's long sleeves into another value
            ['attribute' => 'sleeve_type', 'key' => 'belted_sleeve', 'patterns' => ['\bbelted\b'], 'same_as' => ''] + $label('Belted'),
        ], 'attributes' => [
            // the sleeves again, under another name
            ['key' => 'sleeve_length', 'applies_to' => [], 'visible' => true, 'values' => [
                ['key' => 'long', 'patterns' => ['\blong sleeves?\b']] + $label('Long'),
                ['key' => 'short', 'patterns' => ['\bshort sleeves?\b']] + $label('Short'),
            ]] + $label('Sleeve length'),
        ]];

        app(CompleteVocabulary::class)->handle($this->shop->id, false, $report);

        $why = array_column($report['rejected'], 'why', 'item');
        $this->assertStringStartsWith('changes or takes what 1 products had', $why['sleeve_type.belted_sleeve'] ?? '', 'what a product had stays as it was');
        $this->assertStringStartsWith('repeats the attribute sleeve_type', $why['attribute sleeve_length'] ?? '');
        $this->assertSame([], $report['added']);
    }

    public function test_a_value_that_is_another_under_a_new_name_joins_it(): void
    {
        $merged = CompleteVocabulary::merge($this->inShop(fn () => EnrichmentVocabulary::query()->where('active', true)->sole()->definition()), [
            'types' => [], 'attributes' => [],
            'values' => [['attribute' => 'sleeve_type', 'key' => 'long', 'label_he' => 'x', 'label_en' => 'x', 'patterns' => ['\blong-sleeved\b'], 'same_as' => 'long_sleeve']],
        ]);

        $values = $merged[0]->attribute('sleeve_type')['values'];
        $this->assertSame(['long_sleeve', 'short_sleeve'], array_column($values, 'key'), 'no second value');
        $this->assertContains('\blong-sleeved\b', $values[0]['patterns'], 'its words join the value it is');
    }
}
