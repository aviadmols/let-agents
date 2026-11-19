<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Actions\MeasureBankQuality;
use App\Modules\Enrichment\Models\EnrichmentExpertBank;
use App\Modules\Enrichment\Scanning\BankEvidence;
use App\Modules\Enrichment\Scanning\BankQuality;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A bank's types measured beyond the share they cover (Aviad, 19.11): by the store's own departments, by how specific they
 * are, and how often right on a sample judged by the other family. Activates nothing. Any store.
 */
final class BankQualityTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $asked = [];

    public function test_departments_and_specificity_are_measured_in_code(): void
    {
        $texts = [];
        $add = function (string $title, string $department) use (&$texts): void {
            $texts[] = ['id' => (string) count($texts), 'title' => $title, 'head' => TextNormalizer::forMatching($title.' ; '.$department), 'full' => '', 'department' => $department];
        };
        // furniture: five kinds of two each, all under one type: a department taken for a type
        foreach (['Wardrobe', 'Crib', 'Mattress', 'Dresser', 'Shelf'] as $kind) {
            $add($kind.' white', 'Furniture');
            $add($kind.' oak', 'Furniture');
        }
        // toys: two dolls typed, four toys whose names say no kind left untyped
        $add('Doll Anna', 'Toys');
        $add('Doll Mia', 'Toys');
        foreach (['Crawler push and go', 'Cloud university', 'Rattle set', 'Stacking rings'] as $title) {
            $add($title, 'Toys');
        }
        $bank = ['types' => [
            ['key' => 'furniture', 'label' => 'Furniture', 'words' => ['furniture']],
            ['key' => 'doll', 'label' => 'Doll', 'words' => ['doll']],
        ], 'attributes' => []];

        $typeOf = BankQuality::typeOf($bank, (new BankEvidence($texts))->typeSets($bank));
        $this->assertCount(12, $typeOf);

        $departments = array_column(BankQuality::departments($texts, $typeOf), null, 'department');
        $this->assertSame(['products' => 10, 'typed' => 10, 'share' => 1.0, 'weak' => false], array_diff_key($departments['Furniture'], ['department' => 1]));
        $this->assertSame(['products' => 6, 'typed' => 2, 'share' => 0.333, 'weak' => true], array_diff_key($departments['Toys'], ['department' => 1]), 'a department the bank reads poorly shows');

        $specificity = BankQuality::specificity($texts, $typeOf, $bank);
        $types = array_column($specificity['types'], null, 'key');
        $this->assertTrue($types['furniture']['broad'], 'five kinds under one type');
        $this->assertSame(5, $types['furniture']['kinds']);
        $this->assertFalse($types['doll']['broad']);
        $this->assertSame(round(10 / 12, 3), $specificity['broad_share']);
    }

    public function test_how_often_a_type_is_right_is_judged_on_a_sample_by_the_other_family(): void
    {
        $this->buildShop();
        foreach (['Doll Anna', 'Doll Mia', 'Doll set with stroller'] as $i => $title) {
            $this->product('d'.$i, $title, 'A doll.', [], ['payload' => ['product_type' => 'Toys']]);
        }
        $this->product('w1', 'Wardrobe white', 'A wardrobe.', [], ['payload' => ['product_type' => 'Furniture']]);
        $bank = $this->inShop(fn () => EnrichmentExpertBank::query()->create(['shop_id' => $this->shop->id, 'status' => EnrichmentExpertBank::HELD, 'fingerprint' => str_repeat('a', 64), 'products' => 4,
            'bank' => ['types' => [['key' => 'doll', 'label' => 'בובה', 'words' => ['doll']], ['key' => 'wardrobe', 'label' => 'ארון', 'words' => ['wardrobe']]], 'attributes' => []], 'measurement' => ['type_share' => 1.0]]));

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel, StructuredChatModel
        {
            public function __construct(private readonly BankQualityTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return $this->structured($provider, $model, $system, $user, [], $maxOutputTokens, $reasoningEffort);
            }

            public function structured(AiProviderName $provider, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $items = (array) (json_decode($user, true)['items'] ?? []);
                $this->test->asked[] = ['provider' => $provider, 'items' => $items];

                // the doll with a stroller is judged no doll; the rest right
                return new ModelReply(['verdicts' => array_map(fn (array $i): array => ['id' => $i['id'], 'verdict' => str_contains($i['product']['title'], 'stroller') ? 'incorrect' : 'correct'], $items)], 900, 200);
            }
        });

        $run = app(MeasureBankQuality::class)->handle($this->shop->id, $bank->id);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertCount(1, $this->asked, 'one call');
        $this->assertCount(4, $this->asked[0]['items'], 'every typed product, the sample being larger');
        $this->assertSame(['attribute' => 'product type', 'value' => 'בובה'], collect($this->asked[0]['items'])->firstWhere('product.title', 'Doll Anna')['tag']);
        $quality = $this->inShop(fn () => $bank->fresh()->measurement['quality']);
        $this->assertSame(['judged' => 4, 'sure' => 4, 'correct' => 3, 'incorrect' => 1, 'share' => 0.75], array_diff_key($quality['accuracy'], ['wrong' => 1]));
        $this->assertSame([['title' => 'Doll set with stroller', 'type' => 'בובה']], $quality['accuracy']['wrong']);
        $this->assertEquals(1.0, $this->inShop(fn () => $bank->fresh()->measurement['type_share']), 'the measurement it had stays');
    }
}
