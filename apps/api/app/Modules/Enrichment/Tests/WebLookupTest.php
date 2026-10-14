<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\WebAnswer;
use App\Modules\Ai\Contracts\WebSearchModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\LookUpOnTheWeb;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Operator\Pages\MissingInfo;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Models\EnrichmentWebLookup;
use App\Modules\Enrichment\Scanning\WebEvidence;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A missing detail asked of the web: a value is offered only when code finds the sentence on two
 * independent pages the search really returned (or on the manufacturer's), a model of the other
 * family agrees the sentences are about this product, and a person approves it.
 */
final class WebLookupTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<WebAnswer> */
    public array $searches = [];

    /** @var list<array<string, mixed>> */
    public array $verdicts = [];

    public int $verifierCalls = 0;

    private EnrichmentVocabulary $vocabulary;

    private CatalogProduct $drill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        $this->shop->update(['domain' => 'tools-store.co.il']);

        $data = ImportVocabulary::template('power-tools');
        $data['root_category_external_id'] = '*';
        $data['excluded_category_external_ids'] = [];
        foreach ($data['attributes'] as &$attribute) {
            if ($attribute['key'] === 'kit') {
                $attribute['importance'] = 'required';
                $attribute['applies_to'] = ['impact_driver'];
            }
        }
        unset($attribute);
        $this->vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, $data, 'test')['vocabulary'];

        $this->drill = $this->product('1', 'מברגת אימפקט מקיטה DTD153RME', '', [], ['brand' => 'Makita', 'sku' => 'DTD153RME']);
        $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $this->drill->id, 'vocabulary_id' => $this->vocabulary->id, 'kind' => FactKind::Type, 'key' => 'type',
            'value_text' => 'impact_driver', 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => 'h1',
        ]));

        $test = $this;
        $this->app->instance(WebSearchModel::class, new class($test) implements WebSearchModel
        {
            public function __construct(private readonly WebLookupTest $test) {}

            public function research(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, int $maxSearches): WebAnswer
            {
                return array_shift($this->test->searches);
            }
        });
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly WebLookupTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->verifierCalls++;

                return new ModelReply(array_shift($this->test->verdicts) ?? [], 800, 200);
            }
        });

        Http::fake([
            'https://makita-dealer.co.il/*' => Http::response('<html><body><h1>DTD153RME</h1><p>מברגת אימפקט DTD153RME כולל 2 סוללות ומטען, תיק נשיאה.</p></body></html>'),
            'https://www.toolsreview.com/*' => Http::response('<html><body><table><tr><td>DTD153RME: כולל 2 סוללות ומטען</td></tr></table></body></html>'),
            'https://tools-store.co.il/*' => Http::response('<p>DTD153RME כולל 2 סוללות ומטען</p>'),
            '*' => Http::response('not found', 404),
        ]);
    }

    public function test_two_independent_pages_that_hold_the_sentence_make_it_certain_and_a_person_approves_it(): void
    {
        $this->searches[] = $this->answer([
            ['url' => 'https://makita-dealer.co.il/dtd153rme', 'quote' => 'מברגת אימפקט DTD153RME כולל 2 סוללות ומטען'],
            ['url' => 'https://www.toolsreview.com/dtd153rme', 'quote' => 'DTD153RME: כולל 2 סוללות ומטען'],
        ], ['https://makita-dealer.co.il/dtd153rme', 'https://www.toolsreview.com/dtd153rme']);
        $this->verdicts[] = ['claims' => [['key' => 'kit', 'same_product' => true, 'why' => 'same model']]];

        $out = $this->inShop(fn (): array => app(LookUpOnTheWeb::class)->handle($this->drill->id, ['kit']));

        $this->assertNull($out['problem']);
        $lookup = $out['lookup'];
        $this->assertSame(EnrichmentWebLookup::CERTAIN, $lookup->certainty);
        $this->assertSame('with_batteries', $lookup->results['kit']['value']);
        $this->assertSame([true, true], array_column($lookup->results['kit']['sources'], 'verified'));
        $this->assertGreaterThan(0, (float) $lookup->cost_usd, 'the searches and both models are counted');
        $this->assertSame(0, $this->inShop(fn () => EnrichmentFact::query()->where('key', 'kit')->count()), 'nothing is a fact before a person approves');

        $done = $this->inShop(fn (): array => app(LookUpOnTheWeb::class)->decide($lookup, 'kit', true));

        $this->assertTrue($done['ok']);
        $fact = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $this->drill->id)->where('key', 'kit')->where('status', FactStatus::Approved)->first());
        $this->assertSame('with_batteries', $fact->value_text);
        $this->assertSame(FactOrigin::Person, $fact->origin);
        $this->assertSame(['https://makita-dealer.co.il/dtd153rme', 'https://www.toolsreview.com/dtd153rme'], array_column($fact->evidence['web'], 'url'), 'the sources it rested on stay with it');
        $this->assertSame(EnrichmentWebLookup::APPROVED, $lookup->refresh()->results['kit']['decision']);
    }

    public function test_a_source_the_search_did_not_return_a_sentence_the_page_does_not_hold_and_the_stores_own_page_prove_nothing(): void
    {
        $this->searches[] = $this->answer([
            ['url' => 'https://invented.example/dtd153rme', 'quote' => 'DTD153RME כולל 2 סוללות ומטען'],                  // never returned by the search
            ['url' => 'https://www.toolsreview.com/dtd153rme', 'quote' => 'DTD153RME מגיע עם 2 סוללות ומטען כלול'],      // not on the page
            ['url' => 'https://tools-store.co.il/p/1', 'quote' => 'DTD153RME כולל 2 סוללות ומטען'],                        // the store itself
        ], ['https://www.toolsreview.com/dtd153rme', 'https://tools-store.co.il/p/1']);

        $lookup = $this->inShop(fn (): array => app(LookUpOnTheWeb::class)->handle($this->drill->id, ['kit']))['lookup'];

        $this->assertSame(EnrichmentWebLookup::NOT_FOUND, $lookup->certainty);
        $this->assertSame(['not_in_results', 'quote_not_on_page', ''], array_column($lookup->results['kit']['sources'], 'why'));
        $this->assertSame([false, false, true], array_column($lookup->results['kit']['sources'], 'verified'), 'the store\'s page holds it');
        $this->assertSame([false, false, false], array_column($lookup->results['kit']['sources'], 'independent'), 'but the store is not evidence about itself');
        $this->assertSame(0, $this->verifierCalls, 'nothing to check: the other model is not asked');
    }

    public function test_one_site_is_only_likely_unless_it_is_the_manufacturers_and_the_other_family_can_say_it_is_another_product(): void
    {
        $one = fn (): WebAnswer => $this->answer([['url' => 'https://makita-dealer.co.il/dtd153rme', 'quote' => 'מברגת אימפקט DTD153RME כולל 2 סוללות ומטען']], ['https://makita-dealer.co.il/dtd153rme']);

        $this->searches[] = $one();
        $this->verdicts[] = ['claims' => [['key' => 'kit', 'same_product' => true, 'why' => '']]];
        $this->assertSame(EnrichmentWebLookup::LIKELY, $this->inShop(fn (): array => app(LookUpOnTheWeb::class)->handle($this->drill->id, ['kit']))['lookup']->certainty);

        // The manufacturer's own site is enough. A new question (another prompt or detail set) is a new lookup.
        $this->inShop(fn () => EnrichmentWebLookup::query()->delete());
        Settings::set('enrichment.web_manufacturer_domains', 'makita-dealer.co.il', $this->shop->id);
        $this->searches[] = $one();
        $this->verdicts[] = ['claims' => [['key' => 'kit', 'same_product' => true, 'why' => '']]];
        $this->assertSame(EnrichmentWebLookup::CERTAIN, $this->inShop(fn (): array => app(LookUpOnTheWeb::class)->handle($this->drill->id, ['kit']))['lookup']->certainty);

        $this->inShop(fn () => EnrichmentWebLookup::query()->delete());
        $this->searches[] = $one();
        $this->verdicts[] = ['claims' => [['key' => 'kit', 'same_product' => false, 'why' => 'DTD153Z is body only']]];
        $lookup = $this->inShop(fn (): array => app(LookUpOnTheWeb::class)->handle($this->drill->id, ['kit']))['lookup'];
        $this->assertSame(EnrichmentWebLookup::NOT_FOUND, $lookup->certainty);
        $this->assertSame('other_product', $lookup->results['kit']['why']);
    }

    public function test_it_asks_only_with_a_strong_identifier_never_twice_within_the_limits_and_with_two_families(): void
    {
        $plain = $this->product('2', 'מברגה', '', []);
        $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $plain->id, 'vocabulary_id' => $this->vocabulary->id, 'kind' => FactKind::Type, 'key' => 'type',
            'value_text' => 'impact_driver', 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => 'h2',
        ]));
        $look = fn (string $id, array $keys = ['kit']): array => $this->inShop(fn (): array => app(LookUpOnTheWeb::class)->handle($id, $keys));

        $this->assertSame('no_identifier', $look($plain->id)['problem'], 'no brand and no model: a search would find a similar product');
        $this->assertSame('nothing_to_ask', $look($this->drill->id, ['brushless'])['problem'], 'a yes or no is not asked');

        $this->searches[] = $this->answer([], []);
        $first = $look($this->drill->id);
        $again = $look($this->drill->id);
        $this->assertTrue($again['kept'], 'the same question within 90 days is answered from what was kept');
        $this->assertSame($first['lookup']->id, $again['lookup']->id);
        $this->assertSame([], $this->searches, 'and no search was made for it');

        $this->inShop(fn () => EnrichmentWebLookup::query()->delete());
        Settings::set('enrichment.web_checks_per_month', 1, $this->shop->id);
        $this->searches[] = $this->answer([], []);
        $look($this->drill->id);
        $this->assertSame('monthly_limit', $look($this->drill->id, ['kit', 'power_source'])['problem']);

        Settings::set('enrichment.web_checks_per_month', 0, $this->shop->id);
        $this->assertSame('off', $look($this->drill->id)['problem']);

        Settings::set('enrichment.web_checks_per_month', 50, $this->shop->id);
        Settings::set('enrichment.web_verifier_provider', 'anthropic');
        $this->assertSame('same_family', $look($this->drill->id, ['power_source'])['problem']);
    }

    public function test_on_the_screen_the_team_checks_a_product_sees_the_sources_and_approves_and_only_the_operator_sees_the_cost(): void
    {
        $this->searches[] = $this->answer([
            ['url' => 'https://makita-dealer.co.il/dtd153rme', 'quote' => 'מברגת אימפקט DTD153RME כולל 2 סוללות ומטען'],
            ['url' => 'https://www.toolsreview.com/dtd153rme', 'quote' => 'DTD153RME: כולל 2 סוללות ומטען'],
        ], ['https://makita-dealer.co.il/dtd153rme', 'https://www.toolsreview.com/dtd153rme']);
        $this->verdicts[] = ['claims' => [['key' => 'kit', 'same_product' => true, 'why' => '']]];

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);

        $page = Livewire::test(MissingInfo::class)
            ->call('toggleGroup', 'impact_driver|kit')
            ->call('lookUp', $this->drill->id)   // the queue runs it at once in tests
            ->call('toggleGroup', 'impact_driver|kit')->call('toggleGroup', 'impact_driver|kit')
            ->assertSeeHtml('data-web-result="certain"')
            ->assertSee('נמצא: כולל סוללות ומטען')
            ->assertSee('makita-dealer.co.il')
            ->assertSee('עלות');

        $lookup = $this->inShop(fn () => EnrichmentWebLookup::query()->first());
        $page->call('decideWeb', $lookup->id, 'kit', true);
        $this->assertSame(['with_batteries'], $this->inShop(fn () => EnrichmentFact::query()->where('key', 'kit')->where('status', FactStatus::Approved)->pluck('value_text')->all()));

        // The shop's manager sees the same answer, not what it cost.
        $this->inShop(fn () => EnrichmentFact::query()->where('key', 'kit')->update(['status' => FactStatus::Superseded]));
        $this->inShop(function () use ($lookup): void {
            $results = $lookup->refresh()->results;
            $results['kit']['decision'] = EnrichmentWebLookup::PENDING;
            $lookup->update(['results' => $results]);
        });
        $manager = User::factory()->create();
        $manager->attachShop($this->shop, ShopRole::Owner);
        $html = $this->actingAs($manager)->withHeader('Accept-Language', 'he')->get("/merchant/{$this->shop->slug}/enrichment/missing-info")->assertOk()->getContent();
        $this->assertStringNotContainsString('data-web-log', $html);
        $this->assertDoesNotMatchRegularExpression('/עלות|claude|gpt|מודל/iu', strip_tags($html));
    }

    public function test_code_reads_sites_and_sentences_the_same_way_every_time(): void
    {
        $this->assertSame('makita.co.il', WebEvidence::site('www.shop.makita.co.il'));
        $this->assertSame('toolsreview.com', WebEvidence::site('www.toolsreview.com'));
        $this->assertNull(WebEvidence::host('javascript:alert(1)'));
        $this->assertTrue(WebEvidence::inList('www.makita.co.il', 'bosch.com, makita.co.il'));
        $this->assertTrue(WebEvidence::quoteOn(WebEvidence::plain('<p>Weight: <b>2.5&nbsp;kg</b></p>'), 'Weight: 2.5 kg'));
        $this->assertFalse(WebEvidence::quoteOn('weight 2.5 kg', 'kg'), 'too short to prove anything');
        $this->assertTrue(WebEvidence::says('משקל 2,5 ק"ג', 2.5));
        $this->assertTrue(WebEvidence::says('weight 2500 g', 2.5), 'grams for kilograms');
        $this->assertFalse(WebEvidence::says('weight 3 kg', 2.5));
    }

    /**
     * @param  list<array{url: string, quote: string}>  $sources
     * @param  list<string>  $urls  what the search returned
     */
    private function answer(array $sources, array $urls): WebAnswer
    {
        $value = $sources === [] ? null : 'with_batteries';

        return new WebAnswer(new ModelReply(['answers' => [[
            'key' => 'kit', 'value' => $value, 'conflict' => false,
            'sources' => array_map(fn (array $s): array => $s + ['same_product' => true], $sources),
        ]]], 18000, 600), $urls, 2);
    }
}
