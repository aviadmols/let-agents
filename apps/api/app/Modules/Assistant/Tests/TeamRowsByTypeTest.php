<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What the team wrote reaches a question by its kind (Rocks, 16.11: parking and Layaway were sent with "what is a carat?"):
 * a question about the store keeps every row, its address and hours among them; one about a product or a term only the
 * rows that speak of it. Tried in a quality run before it is on (assistant.team_rows_by_type).
 */
final class TeamRowsByTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_term_question_gets_the_rows_that_speak_of_it_and_a_store_question_all_of_them(): void
    {
        $shop = Shop::factory()->create();
        $knowledge = fn (string $q): string => implode("\n", array_map(fn (array $p): string => (string) $p['text'], app(TenantContext::class)->run($shop->id, function () use ($q, $shop): array {
            return array_values(array_filter((new ReflectionMethod(AnswerSiteQuestion::class, 'knowledge'))->invoke(app(AnswerSiteQuestion::class), $q, $shop->id, []), fn (array $p): bool => ($p['from'] ?? null) === 'store_team'));
        })));
        app(TenantContext::class)->run($shop->id, function (): void {
            foreach (['החניון הקרוב הוא חניון מתחם התחנה.', 'תוכנית Layaway לפריטים מ־2,000 ש״ח ומעלה.', 'כל הזהב שלנו מוטבע בחותמת קראט.'] as $row) {
                app(BuildShopKnowledge::class)->addOwn($row);
            }
        });

        // as it is today: every row, whatever is asked
        $this->assertStringContainsString('חניון', $knowledge('מה זה קראט?'));

        config(['assistant.team_rows_by_type' => true]);
        $term = $knowledge('מה זה קראט?');
        $this->assertStringContainsString('חותמת קראט', $term, 'the row that speaks of it');
        $this->assertStringNotContainsString('חניון', $term);
        $this->assertStringNotContainsString('Layaway', $term);
        $returns = $knowledge('אפשר להחזיר מוצר?');
        $this->assertStringContainsString('חניון', $returns, 'a question about the store keeps every row');
        $this->assertStringContainsString('Layaway', $returns);
    }
}
