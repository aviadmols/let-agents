<?php

namespace App\Modules\Improvement\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The daily reports, a card a day: on the shop's home each kind is a slider of days (the questions
 * shoppers asked and what entered the bank of answers; the site's daily check and what waits for
 * the shop), and the operator sees every shop's by day.
 */
final class DailyReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shops_home_shows_each_day_as_a_card_of_each_kind(): void
    {
        $shop = $this->reports();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        $this->actingAs($merchant)->get("/merchant/{$shop->slug}/overview")->assertOk()
            ->assertSee(__('assistant::ui.daily.label'))
            ->assertSee(__('assistant::ui.daily.headline', ['score' => '64', 'asks' => '67']))
            ->assertSeeInOrder([__('assistant::ui.daily.in', ['count' => 1]), 'על מה הספר חטוף?', __('assistant::ui.daily.out_shop', ['count' => 1]), 'הספר של עומר שם טוב או החטוף?'])
            ->assertDontSee('אמר שהספר לא נמצא.', false)
            ->assertSee(__('improvement::ui.daily.label'))
            ->assertSeeInOrder([__('improvement::ui.daily.waiting', ['count' => 1]), 'להוסיף מילה נרדפת: ספרי ילדים', __('improvement::ui.daily.done', ['count' => 1]), 'תשובה: מדיניות החזרות'])
            ->assertSee('גולשים חיפשו ספרי ילדים ולא מצאו.')
            ->assertDontSee('$0.04', false);

        $this->actingAs($merchant)->get('/operator/daily-reports')->assertForbidden();
    }

    public function test_the_operator_sees_every_shops_reports_by_day(): void
    {
        $this->reports();
        Filament::setCurrentPanel(Filament::getPanel('operator'));

        $this->actingAs(User::factory()->operator()->create())->get('/operator/daily-reports')->assertOk()
            ->assertSee(__('admin::daily.title'))
            ->assertSee('סלע מאיר')
            ->assertSee(__('assistant::ui.daily.headline', ['score' => '64', 'asks' => '67']))
            ->assertSee(__('improvement::ui.daily.headline', ['proposed' => 2, 'applied' => 1]))
            ->assertSee('הספר של עומר שם טוב או החטוף? — אמר שהספר לא נמצא.', false);
    }

    private function reports(): Shop
    {
        $shop = Shop::factory()->create(['name' => 'סלע מאיר']);
        $yesterday = now()->subDay()->startOfDay();
        app(TenantContext::class)->run($shop->id, function () use ($shop, $yesterday): void {
            $in = AssistantSearchAsk::query()->create(['shop_id' => $shop->id, 'day' => $yesterday->toDateString(), 'question' => 'על מה הספר חטוף?', 'products' => [], 'outcome' => 'answered', 'from' => 'model', 'whatsapp_shown' => false, 'whatsapp_clicked' => false]);
            $out = AssistantSearchAsk::query()->create(['shop_id' => $shop->id, 'day' => $yesterday->toDateString(), 'question' => 'הספר של עומר שם טוב או החטוף?', 'products' => [], 'outcome' => 'answered', 'from' => 'model', 'whatsapp_shown' => false, 'whatsapp_clicked' => false]);
            AssistantAskReview::query()->create(['shop_id' => $shop->id, 'day' => $yesterday->toDateString(), 'status' => AssistantAskReview::REVIEWED, 'score' => 64, 'summary' => 'רוב השאלות נענו היטב.',
                'items' => [['ask_id' => $in->id, 'score' => 5, 'note' => 'מדויק.', 'bank' => 'in'], ['ask_id' => $out->id, 'score' => 2, 'note' => 'אמר שהספר לא נמצא.', 'bank' => 'out']],
                'counts' => ['asks' => 67, 'answered' => 60, 'no_match' => 7, 'whatsapp_shown' => 0, 'whatsapp_clicked' => 0, 'picked' => 3]]);
            $review = ImprovementReview::query()->create(['shop_id' => $shop->id, 'day' => $yesterday->toDateString(), 'status' => ImprovementReview::REVIEWED, 'evidence' => [], 'proposed' => 2, 'applied' => 1, 'digest' => "גולשים חיפשו ספרי ילדים ולא מצאו.\nתקציב: \$0.04"]);
            foreach ([['להוסיף מילה נרדפת: ספרי ילדים', ImprovementProposal::PENDING], ['תשובה: מדיניות החזרות', ImprovementProposal::APPLIED]] as [$title, $status]) {
                ImprovementProposal::query()->create(['shop_id' => $shop->id, 'review_id' => $review->id, 'kind' => ImprovementProposal::FAQ, 'title' => $title, 'detail' => [], 'evidence' => [],
                    'fingerprint' => hash('sha256', $title), 'status' => $status, 'proposed_by' => 'x']);
            }
        });

        return $shop;
    }
}
