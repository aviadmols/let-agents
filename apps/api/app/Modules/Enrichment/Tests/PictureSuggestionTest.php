<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\SuggestFromPicture;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Operator\Pages\MissingInfo;
use App\Modules\Enrichment\Jobs\SuggestFromPictureJob;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\RequiredGaps;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A missing detail of how a product looks, suggested from its picture and approved by a person:
 * only a detail a picture can show, only from the vocabulary's values, only when sure enough, and
 * the same picture is never asked twice.
 */
final class PictureSuggestionTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var array<string, array{value: mixed, sure: float}> the model's answer by product title */
    public array $answers = [];

    public int $calls = 0;

    private EnrichmentVocabulary $vocabulary;

    /** @var array<string, CatalogProduct> */
    private array $rings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildShop();
        $this->vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('jewelry'), 'test')['vocabulary'];

        foreach (['1' => 'טבעת סוליטר', '2' => 'טבעת וינטאג', '3' => 'טבעת פשוטה'] as $id => $title) {
            $this->rings[$id] = $this->product($id, $title, '', [], ['image_url' => "https://cdn.test/{$id}.png"]);
            // Code read the type; the metal is missing.
            $this->inShop(fn () => EnrichmentFact::query()->create(['product_id' => $this->rings[$id]->id, 'vocabulary_id' => $this->vocabulary->id, 'kind' => FactKind::Type, 'key' => 'type',
                'value_text' => 'ring', 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => str_repeat('c', 64)]));
        }

        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($image);
        Http::fake(['cdn.test/*' => Http::response((string) ob_get_clean(), 200, ['Content-Type' => 'image/png'])]);

        $this->answers = [
            'טבעת סוליטר' => ['value' => 'yellow_gold', 'sure' => 0.95],
            'טבעת וינטאג' => ['value' => 'white_gold', 'sure' => 0.5],
            'טבעת פשוטה' => ['value' => 'plastic', 'sure' => 0.99],
        ];

        $test = $this;
        $this->app->instance(VisionModel::class, new class($test) implements VisionModel
        {
            public function __construct(private readonly PictureSuggestionTest $test) {}

            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls++;
                $title = json_decode($user, true)['title'];

                return new ModelReply($this->test->answers[$title] ?? ['value' => 'unknown', 'sure' => 0], 1600, 20);
            }
        });
    }

    public function test_a_look_is_suggested_only_when_sure_and_only_from_the_list_and_never_asked_twice(): void
    {
        $run = app(SuggestFromPicture::class)->handle($this->shop->id, $this->vocabulary->id, 'metal', array_map(fn (CatalogProduct $p): string => $p->id, $this->rings));

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(3, $this->calls);
        $this->assertSame([1, 2], [$run->output['suggested'], $run->output['unsure']], 'half sure, or a value not on the list: no suggestion');

        $gap = collect($this->inShop(fn (): array => RequiredGaps::of()))->firstWhere('attribute', 'metal');
        $first = collect($gap['missing'])->firstWhere('id', $this->rings['1']->id);
        $this->assertSame(['yellow_gold'], $first['waiting_keys'], 'it waits for a person');
        $this->assertSame([], collect($gap['missing'])->firstWhere('id', $this->rings['2']->id)['waiting_keys']);
        $this->assertSame(0, $this->inShop(fn (): int => EnrichmentFact::query()->where('key', 'metal')->where('status', FactStatus::Approved)->count()), 'nothing approved by itself');

        app(SuggestFromPicture::class)->handle($this->shop->id, $this->vocabulary->id, 'metal', array_map(fn (CatalogProduct $p): string => $p->id, $this->rings));
        $this->assertSame(3, $this->calls, 'the same pictures are not asked again');
    }

    public function test_a_number_or_a_purity_is_never_asked_of_a_picture(): void
    {
        $run = app(SuggestFromPicture::class)->handle($this->shop->id, $this->vocabulary->id, 'gold_karat', [$this->rings['1']->id]);

        $this->assertSame('failed', $run->status->value);
        $this->assertSame(0, $this->calls);
    }

    public function test_the_team_asks_from_the_screen_and_approves_a_suggestion_with_one_press(): void
    {
        app(SuggestFromPicture::class)->handle($this->shop->id, $this->vocabulary->id, 'metal', [$this->rings['1']->id]);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);
        Bus::fake();

        $page = Livewire::test(MissingInfo::class)->call('toggleGroup', 'ring|metal')
            ->assertSeeHtml('data-picture-suggest')
            ->assertSeeHtml('data-accept-waiting')
            ->call('acceptWaiting', $this->rings['1']->id);

        $this->assertSame(['yellow_gold'], $this->inShop(fn (): array => EnrichmentFact::query()->where('product_id', $this->rings['1']->id)->where('key', 'metal')
            ->where('status', FactStatus::Approved)->pluck('value_text')->all()), 'approved as the team\'s own value');

        $page->call('suggestFromPicture');
        Bus::assertDispatched(SuggestFromPictureJob::class, fn (SuggestFromPictureJob $job): bool => $job->attribute === 'metal'
            && collect($job->productIds)->sort()->values()->all() === collect([$this->rings['2']->id, $this->rings['3']->id])->sort()->values()->all(), 'the products without a suggestion');

        $page->call('toggleGroup', 'ring|gold_karat')->assertDontSeeHtml('data-picture-suggest');
    }
}
