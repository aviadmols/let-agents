<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Admin\Panels\PanelNavigation;
use App\Modules\Admin\Support\CurrentShop;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both sidebars are grouped by topic, in a fixed order, and nothing falls outside a group.
 * Both panels wear the LETS look (fonts and the stylesheet), the sign-in page included.
 */
final class PanelSidebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_sidebar_has_its_groups_in_order_and_no_loose_items(): void
    {
        $shop = Shop::factory()->create();

        foreach (['he', 'en'] as $locale) {
            $html = $this->actingAs(User::factory()->operator()->create())
                ->withSession([CurrentShop::SESSION_KEY => $shop->id])
                ->withHeader('Accept-Language', $locale)
                ->get('/operator/shops')
                ->assertOk()
                ->getContent();

            $this->assertSame(
                array_map(fn (string $key): string => __("admin::panels.nav.{$key}", [], $locale), PanelNavigation::OPERATOR),
                $this->groupLabels($html),
            );
        }
    }

    public function test_the_merchant_sidebar_has_its_groups_in_order_and_no_loose_items(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        foreach (['he', 'en'] as $locale) {
            $html = $this->actingAs($merchant)
                ->withHeader('Accept-Language', $locale)
                ->get("/merchant/{$shop->slug}/overview")
                ->assertOk()
                ->getContent();

            $this->assertSame(
                array_map(fn (string $key): string => __("admin::panels.nav.{$key}", [], $locale), PanelNavigation::MERCHANT),
                $this->groupLabels($html),
            );
        }
    }

    public function test_both_panels_and_the_sign_in_page_wear_the_lets_look(): void
    {
        foreach (['/operator/login', '/merchant/login'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-lets-theme', false)
                ->assertSee('family=Outfit', false)
                ->assertSee('--la-lime', false);
        }
    }

    /** @return list<string> every sidebar group's label, top to bottom; an empty one is a loose item */
    private function groupLabels(string $html): array
    {
        preg_match_all('/<li[^>]*\bdata-group-label="([^"]*)"/u', $html, $found);

        return array_map(fn (string $label): string => html_entity_decode($label, ENT_QUOTES), $found[1]);
    }
}
