<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Notices and insights" screen, as an English reviewer walked it: the menu and the page say the same
 * name, each tab says which page, and "an answer you approve here joins the knowledge" is on the tabs where
 * something is approved, never on sign-ups, photo searches or the notices.
 */
final class InboxScreenTest extends TestCase
{
    use RefreshDatabase;

    private const APPROVE = 'An answer you approve here joins the knowledge';

    public function test_the_menu_and_the_title_agree_and_the_approve_hint_is_only_where_answers_are_approved(): void
    {
        $shop = Shop::factory()->create(['content_locale' => 'en']);
        $merchant = User::factory()->locale('en')->create();
        $merchant->attachShop($shop, ShopRole::Owner);
        $this->actingAs($merchant)->withHeader('Accept-Language', 'en');

        foreach (['assistant/questions' => true, 'enrichment/missing-info' => true, 'improvement/suggestions' => true,
            'shoppers/signups' => false, 'search/photos' => false, 'notices' => false] as $page => $hint) {
            $html = (string) $this->get("/merchant/{$shop->slug}/{$page}")->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<h1[^>]*>\s*Notices and insights\s*</h1>#', $html, "{$page}: the screen's name, as the menu says it");
            $this->assertSame($hint, str_contains($html, self::APPROVE), "{$page}: the approve hint");
        }

        // The tabs name the pages; the notices tab is not the screen's name again.
        $this->get("/merchant/{$shop->slug}/notices")->assertSeeInOrder(['Shopper questions', 'Sign-ups', 'Notices']);
    }
}
