<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ActivateExpertBank;
use App\Modules\Enrichment\Actions\BuildExpertBank;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentExpertBank;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\ExpertBankShape;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A stored expert bank that passed, made the store's vocabulary by a person and undone the same way (19.11): a dry run
 * could not be seen on the store. Activation asks no model; the code reading and the search index that follow are the
 * person's to run, and the products are read the longest phrase first, as the bank was measured.
 */
final class ExpertBankActivationTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private EnrichmentVocabulary $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        Features::override(BuildExpertBank::FEATURE, true, $this->shop->id);
        Features::override('enrichment.read_text_in_code', true, $this->shop->id);
        // no model is asked anywhere here
        $this->app->instance(ChatModel::class, new class implements ChatModel
        {
            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                throw new RuntimeException('no model is asked');
            }
        });

        foreach ([
            '1' => 'Aurora Sterling Silver Ring',
            '2' => 'Luna Sterling Silver Ring',
            '3' => 'Stella 14K Gold Over Sterling Silver Ring',
            '4' => 'Nova 14K Gold Over Sterling Silver Necklace',
            '5' => 'Iris Sterling Silver Necklace',
            '6' => 'Sterling Silvery Charm Ring',
        ] as $id => $title) {
            $this->product($id, $title, 'Made in our studio.', []);
        }
        $label = fn (string $he, string $en): array => ['he' => $he, 'en' => $en];
        $this->previous = app(ImportVocabulary::class)->handle($this->shop->id, [
            'schema_version' => 1, 'key' => 'jewelry', 'name' => $label('תכשיטים', 'Jewelry'), 'root_category_external_id' => '*',
            'product_types' => [['key' => 'ring', 'label' => $label('טבעת', 'Ring'), 'patterns' => ['\bring\b']]],
            'attributes' => [],
        ])['vocabulary'];
    }

    public function test_a_bank_that_passed_becomes_the_vocabulary_and_code_reads_its_values_the_longest_phrase_first_and_deactivating_restores_the_previous_one(): void
    {
        $bank = $this->bank();

        ['run' => $run, 'vocabulary' => $vocabulary, 'replaced' => $replaced] = app(ActivateExpertBank::class)->activate($this->shop->id, $bank->id);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(ActivateExpertBank::KEY, $vocabulary->key);
        $this->assertTrue($vocabulary->active);
        $this->assertFalse($this->previous->refresh()->active, 'the previous vocabulary is retired');
        $this->assertSame([['id' => $this->previous->id, 'key' => 'jewelry', 'version' => 1]], $replaced);
        $bank->refresh();
        $this->assertSame(EnrichmentExpertBank::ACTIVATED, $bank->status);
        $this->assertSame($vocabulary->id, $bank->activation['vocabulary_id']);
        $this->assertSame($replaced, $bank->activation['replaced']);
        $this->assertSame(1, $this->inShop(fn () => EnrichmentVocabulary::query()->where('active', true)->count()), 'one vocabulary reads the catalogue');

        // the shape of a ready vocabulary: valid as one, each choice read the longest phrase first and from words only
        $definition = $vocabulary->definition();
        [$parsed, $problems] = VocabularyDefinition::parse($definition->toArray());
        $this->assertSame([], $problems);
        $this->assertSame($bank->id, $parsed->expertBankId());
        $this->assertTrue($parsed->coversWholeCatalog());
        $this->assertSame(['ring', 'necklace'], array_column($definition->productTypes(), 'key'));
        $this->assertSame(['he' => 'Ring', 'en' => 'Ring'], $definition->productTypes()[0]['label']);
        $this->assertSame([ExpertBankShape::pattern('ring')], $definition->productTypes()[0]['patterns']);
        $metal = $definition->attribute('metal');
        $this->assertSame(['type' => 'enum', 'multiple' => false, 'longest_phrase' => true, 'visible' => false, 'applies_to' => ['ring', 'necklace']], array_intersect_key($metal, array_flip(['type', 'multiple', 'longest_phrase', 'visible', 'applies_to'])));
        $this->assertTrue($parsed->longestPhrase('metal'));
        $this->assertFalse($parsed->readFromImage('metal'), 'nothing of the bank was measured on pictures');

        // nothing is read with it until code reads the products
        $this->assertSame(0, $this->inShop(fn () => EnrichmentFact::query()->where('kind', FactKind::Choice)->count()));
        $this->assertSame(RunStatus::Succeeded, app(ReadProductsInCode::class)->handle($this->shop->id)->status);

        $this->assertSame([
            '1' => ['sterling_silver'],
            '2' => ['sterling_silver'],
            '3' => ['gold_over_silver'],
            '4' => ['gold_over_silver'],
            '5' => ['sterling_silver'],
        ], $this->facts(FactKind::Choice, 'metal'), 'inside "gold over sterling silver" the plain metal is not read again; "silvery" is no whole word of it');
        $this->assertSame(['1' => ['ring'], '2' => ['ring'], '3' => ['ring'], '4' => ['necklace'], '5' => ['necklace'], '6' => ['ring']], $this->facts(FactKind::Type, 'type'));
        $this->assertSame($vocabulary->id, $this->inShop(fn () => EnrichmentFact::query()->where('kind', FactKind::Choice)->where('status', FactStatus::Approved)->value('vocabulary_id')));

        // and back: the bank's vocabulary retired, exactly what it replaced restored
        ['run' => $undo, 'restored' => $restored] = app(ActivateExpertBank::class)->deactivate($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $undo->status);
        $this->assertSame([['id' => $this->previous->id, 'key' => 'jewelry', 'version' => 1]], $restored);
        $this->assertTrue($this->previous->refresh()->active);
        $this->assertFalse($vocabulary->refresh()->active);
        $bank->refresh();
        $this->assertSame(EnrichmentExpertBank::PASSED, $bank->status);
        $this->assertArrayHasKey('deactivated_at', $bank->activation);
        $this->assertSame([$this->previous->id], $bank->activation['restored']);

        // read again, the bank's values are gone from the products with it
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->assertSame([], $this->facts(FactKind::Choice, 'metal'));
    }

    public function test_only_a_bank_that_passed_is_activated_and_one_at_a_time(): void
    {
        $held = $this->bank(EnrichmentExpertBank::HELD);
        ['run' => $run, 'vocabulary' => $vocabulary] = app(ActivateExpertBank::class)->activate($this->shop->id, $held->id);
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertNull($vocabulary);
        $this->assertStringContainsString('held', (string) $run->summary());
        $this->assertTrue($this->previous->refresh()->active, 'nothing changed');
        $this->assertSame(EnrichmentExpertBank::HELD, $held->refresh()->status);

        ['run' => $run] = app(ActivateExpertBank::class)->activate($this->shop->id, 'no-such-bank');
        $this->assertSame(RunStatus::Failed, $run->status);

        $first = $this->bank();
        $second = $this->bank();
        $this->assertSame(RunStatus::Succeeded, app(ActivateExpertBank::class)->activate($this->shop->id, $first->id)['run']->status);
        ['run' => $run] = app(ActivateExpertBank::class)->activate($this->shop->id, $second->id);
        $this->assertSame(RunStatus::Failed, $run->status, 'the undo restores what the first one replaced');
        $this->assertSame(EnrichmentExpertBank::PASSED, $second->refresh()->status);

        // with the flag off, nothing is activated; the undo works whatever the flag
        Features::override(BuildExpertBank::FEATURE, false, $this->shop->id);
        $this->assertSame(RunStatus::Succeeded, app(ActivateExpertBank::class)->deactivate($this->shop->id)['run']->status);
        $this->assertSame(RunStatus::Failed, app(ActivateExpertBank::class)->activate($this->shop->id, $first->id)['run']->status);
        $this->assertSame(RunStatus::Failed, app(ActivateExpertBank::class)->deactivate($this->shop->id)['run']->status, 'nothing is activated to undo');
        $this->assertTrue($this->previous->refresh()->active);
    }

    public function test_an_attribute_of_one_value_stays_out_of_the_vocabulary_and_the_bank_is_still_activated(): void
    {
        // Naot, 19.11: "Gender" with "Men" alone (the gate lets whom it is for pass with one value) failed the whole activation
        $bank = $this->bank();
        $this->inShop(function () use ($bank): void {
            $shape = $bank->bank;
            $shape['attributes'][] = ['id' => 'attribute:audience', 'key' => 'audience', 'label' => 'Gender', 'multiple' => false, 'types' => [], 'values' => [
                ['id' => 'value:audience.men', 'key' => 'men', 'label' => 'Men', 'words' => ['men'], 'patterns' => ['men']],
            ]];
            $bank->update(['bank' => $shape]);
        });

        ['run' => $run, 'vocabulary' => $vocabulary] = app(ActivateExpertBank::class)->activate($this->shop->id, $bank->id);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(['metal'], array_column($vocabulary->definition()->attributes(), 'key'));
    }

    public function test_the_command_activates_by_id_prints_what_to_run_next_and_deactivates(): void
    {
        $bank = $this->bank();

        $this->artisan('enrichment', ['step' => 'expert-bank', 'target' => $this->shop->slug, '--activate' => true])
            ->expectsOutputToContain('expert-bank {shop} {id} --activate')
            ->assertFailed();

        $this->artisan('enrichment', ['step' => 'expert-bank', 'target' => $this->shop->slug, 'argument' => $bank->id, '--activate' => true])
            ->expectsOutputToContain('הבנק '.$bank->id.' הוא אוצר המילים של החנות (גרסה 1): 2 סוגים ו־1 מאפיינים. הוצאו משימוש: jewelry v1.')
            ->expectsOutput('  php artisan enrichment code '.$this->shop->slug)
            ->expectsOutput('  php artisan search index '.$this->shop->slug)
            ->assertSuccessful();
        $this->assertSame(EnrichmentExpertBank::ACTIVATED, $bank->refresh()->status);

        $this->artisan('enrichment', ['step' => 'expert-bank', 'target' => $this->shop->slug, '--deactivate' => true])
            ->expectsOutputToContain('הוחזרו: jewelry v1.')
            ->expectsOutput('  php artisan enrichment code '.$this->shop->slug)
            ->assertSuccessful();
        $this->assertTrue($this->previous->refresh()->active);

        // a store that reads no text in code is told its values will not be read until it does
        Features::override('enrichment.read_text_in_code', false, $this->shop->id);
        $this->artisan('enrichment', ['step' => 'expert-bank', 'target' => $this->shop->slug, 'argument' => $bank->id, '--activate' => true])
            ->expectsOutputToContain('enrichment.read_text_in_code')
            ->assertSuccessful();
    }

    /** A stored bank as BuildExpertBank keeps one: two types, a one-value metal with a plain and a longer phrase. */
    private function bank(string $status = EnrichmentExpertBank::PASSED): EnrichmentExpertBank
    {
        $item = fn (string $id, string $key, string $label, array $words): array => ['id' => $id, 'key' => $key, 'label' => $label, 'words' => $words];
        $bank = ['types' => [$item('type:ring', 'ring', 'Ring', ['ring']), $item('type:necklace', 'necklace', 'Necklace', ['necklace'])], 'attributes' => [
            ['id' => 'attribute:metal', 'key' => 'metal', 'label' => 'Metal', 'multiple' => false, 'types' => ['ring', 'necklace'], 'values' => [
                $item('value:metal.sterling_silver', 'sterling_silver', 'Sterling silver', ['sterling silver']),
                $item('value:metal.gold_over_silver', 'gold_over_silver', '14K gold over sterling silver', ['14k gold over sterling silver']),
            ]],
        ]];

        return $this->inShop(fn () => EnrichmentExpertBank::query()->create([
            'shop_id' => $this->shop->id, 'status' => $status, 'fingerprint' => str_repeat('a', 64), 'products' => 6,
            'bank' => ExpertBankShape::withPatterns($bank), 'gate' => ['passed' => $status === EnrichmentExpertBank::PASSED],
        ]));
    }

    /** @return array<string, list<string>> the approved facts of one kind and key, by the product's id in the store */
    private function facts(FactKind $kind, string $key): array
    {
        $out = [];
        $this->inShop(function () use ($kind, $key, &$out): void {
            $external = CatalogProduct::query()->pluck('external_id', 'id');
            foreach (EnrichmentFact::query()->where('kind', $kind)->where('key', $key)->where('status', FactStatus::Approved)->orderBy('value_text')->get() as $fact) {
                $out[(string) $external[$fact->product_id]][] = (string) $fact->value_text;
            }
        });
        ksort($out, SORT_STRING);

        return $out;
    }
}
