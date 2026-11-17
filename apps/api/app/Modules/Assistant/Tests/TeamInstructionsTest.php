<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * A line the team wrote that tells the assistant how to answer (Rocks: "אל תרשום קוד קופון בשיחות…"), as its check read it,
 * is a rule for a writer that reads rules: followed, never a passage it may tell or cite (8a). A writer that reads no
 * rules is given it as before.
 */
final class TeamInstructionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_instruction_of_the_team_is_a_rule_never_a_passage(): void
    {
        $shop = Shop::factory()->create();
        $instruction = 'אל תרשום קוד קופון בשיחות, רק תסביר איך מקבלים אותו';
        app(TenantContext::class)->run($shop->id, function () use ($instruction): void {
            $knowledge = app(BuildShopKnowledge::class);
            $fact = (string) $knowledge->addOwn('החנות בשבזי 31 תל אביב.');
            $rule = (string) $knowledge->addOwn($instruction);
            $knowledge->checked($fact, 'החנות בשבזי 31 תל אביב.', ['status' => 'ok', 'notes' => [], 'kind' => 'information']);
            $knowledge->checked($rule, $instruction, ['status' => 'nothing', 'notes' => [], 'kind' => 'instruction']);
        });

        $read = function (int $version) use ($shop): array {
            $answers = app()->make(AnswerSiteQuestion::class);
            (new ReflectionProperty(AnswerSiteQuestion::class, 'promptVersions'))->setValue($answers, ['answer_site' => $version]);
            $passages = app(TenantContext::class)->run($shop->id, fn (): array => (new ReflectionMethod(AnswerSiteQuestion::class, 'knowledge'))->invoke($answers, 'איפה החנות?', $shop->id, []));
            $rules = app(TenantContext::class)->run($shop->id, fn (): array => (new ReflectionMethod(AnswerSiteQuestion::class, 'rulesFor'))->invoke($answers, $shop->id));

            return [implode("\n", array_column($passages, 'text')), $rules];
        };

        [$text, $rules] = $read(30);
        $this->assertStringContainsString('שבזי 31', $text);
        $this->assertStringNotContainsString('קוד קופון', $text, 'never a passage');
        $this->assertSame([$instruction], $rules);

        [$text, $rules] = $read(29);
        $this->assertStringContainsString('קוד קופון', $text, 'a writer that reads no rules: as before');
        $this->assertSame([], $rules);
    }
}
