<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Actions\MatchProducts;
use App\Modules\Retrieval\Enums\MatchRequestStatus;
use App\Modules\Retrieval\Enums\MatchStatus;
use App\Modules\Retrieval\Models\RetrievalMatch;
use App\Modules\Retrieval\Models\RetrievalMatchRequest;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Code finds the candidates, the model chooses among them, code checks every choice.
 */
final class MatchProductsTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    private object $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->product('10', 'מקדחה נטענת 18V', 'מקדחה נטענת חזקה עם סוללה ליתיום, לקידוח בעץ ובמתכת.');
        $this->product('11', 'מקדחה נטענת 12V', 'מקדחה נטענת קלה עם סוללה ליתיום, לקידוח בעץ ובמתכת.');
        $this->product('20', 'סט מקדחים לבטון', 'סט מקדחים מוקשים לקידוח בבטון.');
        $this->product('30', 'משקפי מגן', 'משקפי מגן שקופים נגד אבק ושבבים.');
        $this->product('40', 'סוללה 18V', 'סוללת ליתיום 18V למקדחה נטענת.', ['in_stock' => false]);
        $this->article('500', 'קידוח בבטון', 'מקדחה, מקדחים ומשקפי מגן: מה צריך כדי לקדוח בקיר.', ['10', '20', '30']);

        foreach (range(1, 3) as $ignored) {
            $this->order(['10', '20']);
        }
        $this->order(['10', '30'], 'history', '-8 months');

        app(BuildIndex::class)->handle($this->shop->id);

        $this->model = new class implements ChatModel
        {
            public int $calls = 0;

            public ?array $input = null;

            /** @var list<string> titles of the products asked about */
            public array $asked = [];

            public ?string $system = null;

            /** @var array<string, mixed> */
            /** the reply, or a function of the input that returns it */
            public mixed $answer = [];

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls++;
                $this->system = $system;
                $this->input = json_decode($user, true);
                $this->asked[] = $this->input['product']['title'];

                return new ModelReply(is_callable($this->answer) ? ($this->answer)($this->input) : $this->answer, 900, 120);
            }
        };
        $this->app->instance(ChatModel::class, $this->model);
    }

    public function test_the_model_chooses_only_from_what_code_found_and_code_checks_every_choice(): void
    {
        $drill = $this->id('10');
        $this->model->answer = function (array $input): array {
            $ref = fn (string $title): ?string => collect($input['candidates'])->firstWhere('title', $title)['ref'] ?? null;

            return [
                'complements' => [
                    ['ref' => $ref('סט מקדחים לבטון'), 'why' => 'נקנה יחד שלוש פעמים'],
                    ['ref' => $ref('משקפי מגן'), 'why' => 'הגנה בזמן קידוח'],
                    ['ref' => 'c99', 'why' => 'invented'],
                ],
                'alternatives' => [
                    ['ref' => $ref('מקדחה נטענת 12V'), 'why' => 'אותה מקדחה, קלה יותר'],
                    ['ref' => $ref('משקפי מגן'), 'why' => 'twice'],
                ],
            ];
        };

        $run = app(MatchProducts::class)->handle($this->shop->id, [$drill]);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);

        // What code offered: the evidence travels with every candidate.
        $offered = collect($this->model->input['candidates'])->keyBy('title');
        $this->assertSame(3, $offered['סט מקדחים לבטון']['signals']['orders_together']);
        $this->assertGreaterThan(0.5, $offered['מקדחה נטענת 12V']['signals']['similarity']);
        $this->assertSame(1, $offered['משקפי מגן']['signals']['guides_together']);
        $this->assertArrayNotHasKey('סוללה 18V', $offered->all(), 'an out-of-stock product is never offered');
        $this->assertStringContainsString('At most 4 complements', $this->model->system, 'the shop\'s numbers are in the prompt');

        $verdicts = $this->inShop(fn () => RetrievalMatch::query()->with('related')->orderBy('kind')->orderBy('position')->get());
        $accepted = $verdicts->where('status', MatchStatus::Accepted)->map(fn (RetrievalMatch $m): string => $m->kind->value.':'.$m->related->title)->values()->all();
        $this->assertSame(['alternative:מקדחה נטענת 12V', 'complement:סט מקדחים לבטון', 'complement:משקפי מגן'], $accepted);
        $this->assertSame(['both_kinds', 'unknown'], $verdicts->where('status', MatchStatus::Rejected)->pluck('rejected_because')->sort()->values()->all(), 'an invented ref and a product picked as both kinds are refused');
        $this->assertSame('נקנה יחד שלוש פעמים', $verdicts->firstWhere('related.title', 'סט מקדחים לבטון')->reason);

        $request = $this->inShop(fn () => RetrievalMatchRequest::query()->sole());
        $this->assertSame(MatchRequestStatus::Answered, $request->status);
        $this->assertSame('gpt-5.4-mini', $request->model);
        $this->assertGreaterThan(0, (float) $request->cost_usd);
        $this->assertSame(['complement' => 2, 'alternative' => 1], $run->output['accepted']);
    }

    public function test_an_unchanged_product_is_not_asked_again_and_a_changed_one_is(): void
    {
        $this->model->answer = ['complements' => [], 'alternatives' => []];
        Settings::set('retrieval.match_products_per_run', 10, $this->shop->id);

        $first = app(MatchProducts::class)->handle($this->shop->id);
        $asked = $this->model->calls;

        $this->assertGreaterThan(0, $asked);
        $this->assertSame($asked, $first->output['asked']);

        $second = app(MatchProducts::class)->handle($this->shop->id);
        $this->assertSame($asked, $this->model->calls, 'nothing changed, nobody asked');
        $this->assertSame($asked, $second->output['unchanged']);

        $this->model->asked = [];
        $this->order(['11', '30']);
        $third = app(MatchProducts::class)->handle($this->shop->id);
        $this->assertSame('succeeded', $third->status->value, (string) $third->error);
        sort($this->model->asked);
        // The two products of the new order, and the drill: the goggles now sell with something
        // else too, so the drill and the goggles are no longer more than chance. The bits' evidence
        // is what it was, and they are not asked again.
        $this->assertSame(['מקדחה נטענת 12V', 'מקדחה נטענת 18V', 'משקפי מגן'], $this->model->asked);
    }

    public function test_best_sellers_are_asked_first_and_the_run_stops_at_its_number(): void
    {
        $this->model->answer = ['complements' => [], 'alternatives' => []];
        Settings::set('retrieval.match_products_per_run', 1, $this->shop->id);

        app(MatchProducts::class)->handle($this->shop->id);

        $this->assertSame(1, $this->model->calls);
        $this->assertSame('מקדחה נטענת 18V', $this->model->input['product']['title'], 'in four orders, more than anything else');
    }

    public function test_matching_keeps_to_its_share_of_the_monthly_cap_and_can_be_switched_off(): void
    {
        $this->app->instance(SpendGuard::class, new class implements SpendGuard
        {
            public function assertCanSpend(float $estimatedUsd): void {}

            public function spentThisMonth(): float
            {
                return 3.5;
            }

            public function cap(): float
            {
                return 6.0;
            }
        });

        $run = app(MatchProducts::class)->handle($this->shop->id);

        $this->assertSame('share_of_cap', $run->output['stopped'], 'more than half the month is spent: the rest is left for shoppers');
        $this->assertSame(0, $this->model->calls);

        Features::override('retrieval.ai_matching', false, $this->shop->id);
        $off = app(MatchProducts::class)->handle($this->shop->id);
        $this->assertSame('off', $off->output['stopped']);
    }

    private function id(string $externalId): string
    {
        return $this->inShop(fn () => CatalogProduct::query()->where('external_id', $externalId)->value('id'));
    }
}
