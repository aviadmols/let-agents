<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Support\StaleOffers;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Tenancy\Models\Shop;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An offer told in an old post is no offer today (Sella, 12.11: a 2024 post on the book week's sales answered "what
 * offers do you have?" in 2026). Any store, Hebrew and English; pages are not touched.
 */
final class StaleOffersTest extends TestCase
{
    use RefreshDatabase;

    public function test_words_of_an_offer_in_hebrew_and_english(): void
    {
        foreach (['מבצע חודש הספר העברי!', 'בחרו 6 ספרים ב-200₪ בלבד', 'קוד הנחה לחברי מועדון', '1+1 על כל החולצות', '20% הנחה', 'Black Friday sale', 'Use the coupon SPRING', '30% off'] as $offer) {
            $this->assertTrue(StaleOffers::talks($offer), $offer);
        }
        foreach (['ראיון עם דאגלס מאריי', 'משלוח תוך 3 ימי עסקים', 'The new collection is here'] as $plain) {
            $this->assertFalse(StaleOffers::talks($plain), $plain);
        }

        $this->assertSame("שבוע הספר מתקרב.\nהספרים החדשים כבר באתר.", StaleOffers::withoutOffers("שבוע הספר מתקרב. מבצע: 6 ספרים ב-200₪!\nהספרים החדשים כבר באתר."));
        $this->assertSame('2024-06-09', StaleOffers::publishedAt('https://www.sellameir.com/2024/06/09/bookweek24/', Carbon::parse('2026-11-01'))?->toDateString(), 'its address, never its last edit');
        $this->assertSame('2026-11-01', StaleOffers::publishedAt('https://shop.test/about/', Carbon::parse('2026-11-01'))?->toDateString());
    }

    public function test_an_old_post_loses_its_offers_and_a_new_post_and_pages_keep_them(): void
    {
        $shop = Shop::factory()->create();
        Carbon::setTestNow('2026-11-12 10:00');

        $passages = app(TenantContext::class)->run((string) $shop->id, function () use ($shop) {
            $old = CatalogContent::query()->create(['type' => CatalogContent::POST, 'external_id' => '1', 'title' => 'שבוע הספר', 'url' => 'https://shop.test/2024/06/09/bookweek24/', 'body' => '-', 'hash' => 'a']);
            $new = CatalogContent::query()->create(['type' => CatalogContent::POST, 'external_id' => '2', 'title' => 'מבצע נובמבר', 'url' => 'https://shop.test/2026/11/01/november/', 'body' => '-', 'hash' => 'b']);
            $page = CatalogContent::query()->create(['type' => CatalogContent::PAGE, 'external_id' => '3', 'title' => 'תקנון', 'url' => 'https://shop.test/terms/', 'body' => '-', 'hash' => 'c', 'source_updated_at' => '2022-01-01']);
            $p = fn (CatalogContent $c, string $text): array => ['source' => 'content', 'source_id' => (string) $c->id, 'external_id' => $c->external_id, 'title' => $c->title, 'text' => $text, 'similarity' => 0.9];

            return app(AnswerSiteQuestion::class)->withoutStaleOffers((string) $shop->id, [
                $p($old, 'מבצע חודש הספר: 6 ספרים ב-200₪ בלבד!'),
                $p($old, "שבוע הספר מתקרב. בחרו 6 ספרים ב-200₪.\nהספרים החדשים של השנה."),
                $p($new, 'מבצע נובמבר: 1+1 על כל הספרים.'),
                $p($page, 'אין כפל הנחות ומבצעים.'),
            ]);
        });

        $this->assertSame(['שבוע הספר מתקרב.'."\n".'הספרים החדשים של השנה.', 'מבצע נובמבר: 1+1 על כל הספרים.', 'אין כפל הנחות ומבצעים.'], array_column($passages, 'text'));
        Carbon::setTestNow();
    }
}
