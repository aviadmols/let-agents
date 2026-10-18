<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Actions\BuildProductVariants;
use App\Modules\Catalog\Models\CatalogColourName;
use App\Modules\Catalog\Models\CatalogVariant;
use App\Modules\Enrichment\Actions\PlaceColourNames;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop's own colour names code does not know are placed once among the shades: one family places,
 * the other checks, only what both agree on is used, and no name is asked twice.
 */
final class ColourNamesTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{provider: AiProviderName, model: string, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly ColourNamesTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'model' => $model, 'user' => $user];

                return new ModelReply(array_shift($this->test->replies) ?? [], 900, 200);
            }
        });

        $this->buildShop();
        $variant = fn (string $id, string $color): array => ['external_id' => $id, 'status' => 'publish', 'attributes' => [['key' => 'Color', 'name' => 'Color', 'value' => $color]], 'stock' => ['in_stock' => true]];
        $shirt = $this->product('1', 'חולצת פשתן', '', [], ['type' => 'variable', 'variations_count' => 4, 'payload' => [
            'attributes' => [['key' => 'Color', 'name' => 'Color', 'values' => ['Ocean Mist', 'Desert Bloom', 'Collection 7', 'שחור'], 'used_for_variations' => true]],
            'variations' => [$variant('11', 'Ocean Mist'), $variant('12', 'Desert Bloom'), $variant('13', 'Collection 7'), $variant('14', 'שחור')],
        ]]);
        $this->inShop(fn () => app(BuildProductVariants::class)->product($shirt));
    }

    public function test_one_family_places_the_other_checks_and_only_what_both_agree_on_is_used(): void
    {
        $this->replies = [
            ['names' => [
                ['name' => 'Collection 7', 'shades' => [], 'pattern' => null],
                ['name' => 'Desert Bloom', 'shades' => ['peach'], 'pattern' => null],
                ['name' => 'Ocean Mist', 'shades' => ['petrol', 'not_a_shade'], 'pattern' => 'floral_ish'],
            ]],
            ['checks' => [['name' => 'Desert Bloom', 'ok' => false], ['name' => 'Ocean Mist', 'ok' => true]]],
        ];

        $run = app(PlaceColourNames::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column($this->calls, 'provider'), 'the reader places, the other family checks');
        $this->assertSame(['Collection 7', 'Desert Bloom', 'Ocean Mist'], json_decode($this->calls[0]['user'], true)['names'], 'only the names code could not place, in a fixed order; "שחור" is known');
        $this->assertGreaterThan(0, (float) $run->cost_usd, 'both calls are on the run');

        $names = $this->inShop(fn () => CatalogColourName::query()->get()->keyBy('name'));
        $this->assertSame([CatalogColourName::PLACED, ['petrol'], null], [$names['Ocean Mist']->status, $names['Ocean Mist']->shades, $names['Ocean Mist']->pattern], 'keys off the list are dropped in code');
        $this->assertSame(CatalogColourName::DISPUTED, $names['Desert Bloom']->status);
        $this->assertSame(CatalogColourName::UNKNOWN, $names['Collection 7']->status);

        // The products named so are read again: Ocean Mist is found as blue and green now; the disputed one is not placed.
        $rows = $this->inShop(fn () => CatalogVariant::query()->get()->keyBy('color_label'));
        $this->assertSame(['blue', 'green'], $rows['Ocean Mist']->color_families);
        $this->assertNull($rows['Desert Bloom']->color_families);

        // Never asked twice.
        app(PlaceColourNames::class)->handle($this->shop->id);
        $this->assertCount(2, $this->calls);
    }

    public function test_a_reader_and_checker_of_one_family_ask_nothing(): void
    {
        Settings::set('enrichment.reviewer_provider', 'openai');
        Settings::set('enrichment.reviewer_model', 'gpt-5.4-mini');

        $run = app(PlaceColourNames::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame([], $this->calls);
    }
}
