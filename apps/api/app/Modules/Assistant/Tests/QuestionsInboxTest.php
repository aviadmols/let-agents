<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Filament\Merchant\Pages\ShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\Question;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The questions shoppers asked, as the shop manager works them: each with the answer it got and the
 * score the daily review gave it, filtered; a question with no answer is answered by the team or set
 * aside, and then it leaves the list.
 */
final class QuestionsInboxTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'Rocks and Gold']);
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        app(TenantContext::class)->set($this->shop->id);
    }

    public function test_each_question_shows_its_answer_and_score_and_the_filters_narrow_them(): void
    {
        $answered = $this->ask('אפשר להחזיר או להחליף?', 'answered', 'כן, עד 14 יום מהקנייה, באריזה המקורית.');
        $this->ask('יש לכם עגילים מזהב לבן?', 'no_info');
        $this->ask('מה מזג האוויר מחר?', 'out_of_scope');
        AssistantAskReview::query()->create(['shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'score' => 80, 'items' => [['ask_id' => $answered->id, 'score' => 85, 'note' => 'מדויק']], 'counts' => [], 'status' => 'reviewed']);

        Livewire::test(ShopQuestions::class)
            ->assertSee('אפשר להחזיר או להחליף?')
            ->assertSee('התשובה שקיבלו')
            ->assertSee('כן, עד 14 יום מהקנייה, באריזה המקורית.')
            ->assertSee('ציון 85')
            ->call('filter', 'open')
            ->assertSee('יש לכם עגילים מזהב לבן?')
            ->assertDontSee('אפשר להחזיר או להחליף?')
            ->assertDontSee('מה מזג האוויר מחר?')
            ->call('filter', 'off')
            ->assertSee('מה מזג האוויר מחר?');
    }

    public function test_a_team_answer_or_not_relevant_takes_the_question_off_the_list(): void
    {
        $this->ask('יש לכם עגילים מזהב לבן?', 'no_info');
        $this->ask('משלוח לאילת?', 'no_info');
        $earrings = 's:'.Question::key('יש לכם עגילים מזהב לבן?');
        $eilat = 's:'.Question::key('משלוח לאילת?');

        Livewire::test(ShopQuestions::class)
            ->call('writeOwn', $earrings)
            ->set("drafts.{$earrings}", 'כן, יש לנו עגילי זהב לבן 14K בכמה דגמים.')
            ->call('publish', $earrings)
            ->assertDontSee('יש לכם עגילים מזהב לבן?')
            ->call('setAside', $eilat)
            ->assertDontSee('משלוח לאילת?')
            ->assertSee('אין שאלות שמתאימות לסינון');

        $team = AssistantAnswer::query()->where('question_key', Question::key('יש לכם עגילים מזהב לבן?'))->first();
        $this->assertSame(['team', AssistantAnswer::ANSWERED, 'כן, יש לנו עגילי זהב לבן 14K בכמה דגמים.'], [$team->source, $team->outcome, $team->answer], 'it joins what the assistant answers from');
        $this->assertSame(AssistantAnswer::HIDDEN, AssistantAnswer::query()->where('question_key', Question::key('משלוח לאילת?'))->value('status'));
    }

    private function ask(string $question, string $outcome, ?string $answer = null): AssistantSearchAsk
    {
        return AssistantSearchAsk::query()->create([
            'shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'question' => $question, 'products' => [],
            'outcome' => $outcome, 'from' => 'model', 'answer' => $answer,
        ]);
    }
}
